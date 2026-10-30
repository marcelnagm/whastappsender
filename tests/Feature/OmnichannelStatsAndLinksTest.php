<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Channels\ContactLinks;
use App\Services\Stats\ChannelStats;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class OmnichannelStatsAndLinksTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        config(['instagram.graph_url' => 'https://ig.test']);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
    }

    private function inst(string $channel, ?User $u = null): Instance
    {
        $u ??= $this->user;

        return Instance::create(['user_id' => $u->id, 'name' => ucfirst($channel), 'instance_name' => $channel . '-' . $u->id, 'status' => 'connected', 'channel' => $channel, 'instagram_token' => 'IGAAtok', 'instagram_id' => '9001']);
    }

    private function convo(Instance $i, string $jid, string $contactValue, string $name, array $msgs, ?User $u = null): Chat
    {
        $u ??= $this->user;
        $contact = Contact::create(['user_id' => $u->id, 'contact' => $contactValue, 'name' => $name, 'status' => str_contains($contactValue, ':') ? 'x' : 'ativo']);
        $chat = Chat::create(['user_id' => $u->id, 'instance_id' => $i->id, 'remote_jid' => $jid, 'name' => $name, 'contact_id' => $contact->id]);
        $last = null;
        foreach ($msgs as $n => [$fromMe, $minutesAgo]) {
            $last = ChatMessage::create([
                'chat_id' => $chat->id, 'instance_id' => $i->id, 'user_id' => $u->id, 'message_id' => $jid . '-' . $n, 'from_me' => $fromMe,
                'type' => 'text', 'body' => 'x', 'status' => $fromMe ? 'sent' : 'received', 'message_timestamp' => now()->subMinutes($minutesAgo),
            ]);
        }
        $chat->update(['last_message_id' => $last->id, 'last_message_from_me' => $last->from_me, 'last_message_at' => $last->message_timestamp, 'last_message_type' => 'text']);

        return $chat->fresh();
    }

    public function test_stats_per_channel(): void
    {
        $wa = $this->inst('whatsapp');
        $ig = $this->inst('instagram');
        $this->convo($wa, '5595@s.whatsapp.net', '5595981110695', 'Ana', [[false, 60], [true, 50], [false, 40]]);   // answered in 10 min, waiting again
        $this->convo($ig, 'ig:1', 'ig:1', 'Bia', [[false, 30], [true, 0]]);                                          // answered in 30 min
        $this->convo($ig, 'ig:2', 'ig:2', 'Caio', [[false, 20]]);                                                    // waiting

        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->convo($this->inst('telegram', $other), 'tg:1', 'tg:1', 'Zed', [[false, 5]], $other); // other account: never counted

        $s = (new ChannelStats())->summary($this->user, 30);
        $this->assertSame(1, $s['channels']['whatsapp']['chats']);
        $this->assertSame(2, $s['channels']['whatsapp']['inbound']);
        $this->assertSame(1, $s['channels']['whatsapp']['outbound']);
        $this->assertSame(10, $s['channels']['whatsapp']['first_response_min']);
        $this->assertSame(1, $s['channels']['whatsapp']['waiting']);

        $this->assertSame(2, $s['channels']['instagram']['chats']);
        $this->assertSame(2, $s['channels']['instagram']['new_chats']);
        $this->assertSame(2, $s['channels']['instagram']['inbound']);
        $this->assertSame(30, $s['channels']['instagram']['first_response_min']);
        $this->assertSame(1, $s['channels']['instagram']['waiting']);
        $this->assertSame(2, $s['channels']['instagram']['contacts']);

        $this->assertSame(0, $s['channels']['telegram']['chats']);
        $this->assertCount(2, $s['instances']);
        $this->assertSame(6, collect($s['daily'])->flatten()->sum(), 'daily series counts every message of this account');
    }

    public function test_stats_page_renders_and_period_is_validated(): void
    {
        $this->convo($this->inst('instagram'), 'ig:1', 'ig:1', 'Bia', [[false, 30], [true, 0]]);
        $this->actingAs($this->user)->get(route('stats.index'))->assertOk()->assertSee('Instagram')->assertSee('TikTok')->assertSee('Telegram')->assertSee('WhatsApp');
        $this->actingAs($this->user)->get(route('stats.index', ['days' => 9999]))->assertOk();
        auth()->logout();
        $this->get(route('stats.index'))->assertRedirect();
    }

    public function test_link_unlink_and_list_across_channels(): void
    {
        $wa = $this->inst('whatsapp');
        $ig = $this->inst('instagram');
        $waChat = $this->convo($wa, '5595@s.whatsapp.net', '5595981110695', 'Ana', [[false, 10]]);
        $igChat = $this->convo($ig, 'ig:7', 'ig:7', 'ana_insta', [[false, 5]]);
        $igContact = $igChat->contact;

        // from the WhatsApp side: candidates are unlinked channel contacts
        $r = $this->actingAs($this->user)->getJson(route('chat.api.links.search', [$waChat, 'q' => 'ana']))->assertOk();
        $this->assertSame([$igContact->id], collect($r->json('items'))->pluck('id')->all());
        $this->assertSame('instagram', $r->json('items.0.channel'));

        $this->actingAs($this->user)->postJson(route('chat.api.links.store', $waChat), ['contact_id' => $igContact->id])->assertOk();
        $this->assertSame($waChat->contact_id, $igContact->fresh()->linked_contact_id);

        // both chats list the same two conversations, from either side
        foreach ([$waChat, $igChat] as $c) {
            $items = $this->actingAs($this->user)->getJson(route('chat.api.links', $c))->assertOk()->json('items');
            $this->assertEqualsCanonicalizing(['whatsapp', 'instagram'], collect($items)->pluck('channel')->all());
        }
        $this->assertEmpty(array_diff([$igContact->id], collect($this->actingAs($this->user)->getJson(route('chat.api.links', $waChat))->json('items'))->where('unlinkable', true)->pluck('contact_id')->all()));

        // already linked: not offered again
        $this->assertEmpty($this->actingAs($this->user)->getJson(route('chat.api.links.search', [$waChat, 'q' => 'ana']))->json('items'));

        // from the Instagram side the candidates are WhatsApp contacts
        $r = $this->actingAs($this->user)->getJson(route('chat.api.links.search', [$igChat, 'q' => '5595']))->assertOk();
        $this->assertSame('whatsapp', $r->json('items.0.channel'));

        $this->actingAs($this->user)->deleteJson(route('chat.api.links.destroy', [$waChat, $igContact]))->assertOk();
        $this->assertNull($igContact->fresh()->linked_contact_id);
    }

    public function test_invalid_links_and_ownership(): void
    {
        $wa = $this->inst('whatsapp');
        $a = $this->convo($wa, 'a@s.whatsapp.net', '5511111111111', 'A', [[false, 1]]);
        $b = $this->convo($wa, 'b@s.whatsapp.net', '5522222222222', 'B', [[false, 1]]);

        $this->actingAs($this->user)->postJson(route('chat.api.links.store', $a), ['contact_id' => $b->contact_id])->assertStatus(422); // two WhatsApp contacts

        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->actingAs($other)->getJson(route('chat.api.links', $a))->assertForbidden();
        $this->actingAs($other)->postJson(route('chat.api.links.store', $a), ['contact_id' => $b->contact_id])->assertForbidden();

        $c = $this->convo($this->inst('instagram'), 'ig:9', 'ig:9', 'I', [[false, 1]]);
        $oc = $this->convo($this->inst('telegram', $other), 'tg:9', 'tg:9', 'T', [[false, 1]], $other);
        $this->actingAs($this->user)->postJson(route('chat.api.links.store', $c), ['contact_id' => $oc->contact_id])->assertNotFound(); // someone else's contact

        $this->assertSame('telegram', ContactLinks::channelOf($oc->contact));
        $this->assertSame('whatsapp', ContactLinks::channelOf($a->contact));
    }

    public function test_send_through_a_linked_channel_uses_that_channels_account(): void
    {
        Http::fake(['ig.test/*/me/messages' => Http::response(['message_id' => 'via-1'])]);
        $wa = $this->inst('whatsapp');
        $ig = $this->inst('instagram');
        $waChat = $this->convo($wa, '5595@s.whatsapp.net', '5595981110695', 'Ana', [[false, 10]]);
        $igChat = $this->convo($ig, 'ig:7', 'ig:7', 'ana_insta', [[false, 5]]);
        ContactLinks::link($igChat->contact, $waChat->contact);

        $items = $this->actingAs($this->user)->getJson(route('chat.api.links', $waChat))->json('items');
        $target = collect($items)->firstWhere('channel', 'instagram');
        $this->assertTrue($target['window_open']);

        // the web client posts to the send endpoint of the chosen conversation
        $this->actingAs($this->user)->postJson(route('chat.api.send', $target['chat_id']), ['text' => 'Oi pelo Instagram'])->assertStatus(201);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/me/messages') && $r['recipient']['id'] === '7');
        $this->assertSame($ig->id, (int) ChatMessage::where('from_me', true)->first()->instance_id);
    }
}
