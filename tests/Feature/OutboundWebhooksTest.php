<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\OutboundWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Automation\Automations;
use App\Services\Webhooks\OutboundWebhooks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Events pushed to the customer's own URLs. */
class OutboundWebhooksTest extends TestCase
{
    use CreatesTestSchema;

    private const URL = 'https://93.184.216.34/hook';

    private User $owner;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $c->id, 'remote_jid' => '5595999990000@s.whatsapp.net', 'name' => 'Ana']);
    }

    private function hook(array $events = ['message.received']): OutboundWebhook
    {
        return OutboundWebhook::create(['user_id' => $this->owner->id, 'name' => 'Zap', 'url' => self::URL, 'secret' => 's3cret', 'events' => $events, 'active' => true]);
    }

    public function test_inbound_message_is_queued_for_subscribed_endpoints_only(): void
    {
        Queue::fake();
        $this->hook(['message.received']);
        $this->hook(['deal.won']);
        ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->chat->instance_id, 'user_id' => $this->owner->id, 'message_id' => 'm1', 'from_me' => false, 'type' => 'text', 'body' => 'Oi', 'message_timestamp' => now()]);

        $this->assertSame(1, WebhookDelivery::count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $body = json_decode(WebhookDelivery::first()->payload, true);
        $this->assertSame('message.received', $body['event']);
        $this->assertSame('Oi', $body['data']['message']['text']);
        $this->assertSame('Ana', $body['data']['chat']['name']);
    }

    public function test_automation_events_are_forwarded(): void
    {
        Queue::fake();
        $this->hook(['tag.added']);
        app(Automations::class)->fire('tag.added', $this->owner, ['chat' => $this->chat, 'tag_id' => 7]);
        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(7, json_decode(WebhookDelivery::first()->payload, true)['data']['tag_id']);
    }

    public function test_delivery_is_signed_and_marked_ok(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $h = $this->hook();
        $d = app(OutboundWebhooks::class)->queue($h, 'ping', ['a' => 1]);

        $this->assertTrue($d->fresh()->ok);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Sama-Signature', OutboundWebhooks::sign($r->body(), 's3cret')) && $r->header('X-Sama-Event') === ['ping']);
        $this->assertNotNull($h->fresh()->last_delivery_at);
    }

    public function test_failed_delivery_records_error_and_counts_after_last_attempt(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $h = $this->hook();
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'ping', 'uuid' => 'u', 'payload' => '{}']);

        $job = new DeliverWebhookJob($d->id);
        $q = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $q->shouldReceive('attempts')->andReturn(1);
        $q->shouldReceive('release')->once()->with(60);
        $job->setJob($q);
        $job->handle();
        $this->assertFalse($d->fresh()->ok);
        $this->assertSame('HTTP 500', $d->fresh()->error);
        $this->assertSame(0, $h->fresh()->failures);
    }

    public function test_private_addresses_are_refused(): void
    {
        foreach (['http://93.184.216.34/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://169.254.169.254/latest', 'https://user:pw@93.184.216.34/x', 'ftp://93.184.216.34'] as $u) {
            $this->assertFalse(OutboundWebhooks::urlAllowed($u), $u);
        }
        $this->assertTrue(OutboundWebhooks::urlAllowed(self::URL));
    }

    public function test_crud_screen(): void
    {
        Queue::fake();
        $this->actingAs($this->owner)->get(route('webhooks-out.index'))->assertOk();
        $this->actingAs($this->owner)->post(route('webhooks-out.store'), ['name' => 'N8N', 'url' => self::URL, 'events' => ['deal.won', 'deal.lost']])->assertRedirect();
        $h = OutboundWebhook::first();
        $this->assertSame(40, strlen($h->secret));
        $this->actingAs($this->owner)->post(route('webhooks-out.store'), ['name' => 'Bad', 'url' => 'https://127.0.0.1/x', 'events' => ['deal.won']])->assertSessionHasErrors('url');
        $this->actingAs($this->owner)->post(route('webhooks-out.store'), ['name' => 'Bad', 'url' => self::URL, 'events' => ['nope']])->assertSessionHasErrors('events.0');

        $this->actingAs($this->owner)->post(route('webhooks-out.test', $h->id))->assertRedirect();
        $this->assertSame('ping', WebhookDelivery::first()->event);
        $this->actingAs($this->owner)->post(route('webhooks-out.toggle', $h->id));
        $this->assertFalse($h->fresh()->active);
        $this->actingAs($this->owner)->delete(route('webhooks-out.destroy', $h->id));
        $this->assertSame(0, OutboundWebhook::count());
    }

    public function test_other_account_cannot_touch_it(): void
    {
        $h = $this->hook();
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->delete(route('webhooks-out.destroy', $h->id))->assertNotFound();
        $this->actingAs($other)->post(route('webhooks-out.test', $h->id))->assertNotFound();
    }
}
