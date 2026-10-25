<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Models\Chat;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatAvatarService;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ChatAvatarTest extends TestCase
{
    use CreatesTestSchema;

    private const PNG = "\x89PNG\r\n\x1a\n-fake-image-bytes";
    private const CDN = 'https://pps.whatsapp.net/v/pic.jpg?sig=abc';

    private User $user;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake('local');
        Cache::flush();
        config(['services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k']]);

        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $this->instance = Instance::create(['user_id' => $this->user->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
    }

    private function chat(array $attrs = [], ?User $owner = null, ?Instance $instance = null): Chat
    {
        $owner ??= $this->user;
        $instance ??= $this->instance;

        return Chat::create($attrs + [
            'user_id' => $owner->id, 'instance_id' => $instance->id,
            'remote_jid' => '5595999@s.whatsapp.net', 'phone' => '5595999',
            'last_message_at' => now(), 'last_message_preview' => 'oi',
        ]);
    }

    private function fakeEvolution(array $picture, $image = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); // earlier fakes would otherwise win
        Http::fake([
            '*/chat/fetchProfilePictureUrl/*' => $picture['response'] ?? Http::response($picture, 200),
            'pps.whatsapp.net/*' => $image ?? Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_picture_is_downloaded_stored_locally_and_served_by_laravel(): void
    {
        $this->fakeEvolution(['profilePictureUrl' => self::CDN]);
        $chat = $this->chat();

        $this->assertSame('stored', app(ChatAvatarService::class)->refresh($chat));

        $chat->refresh();
        $this->assertSame("chat-avatars/{$this->instance->id}/{$chat->id}.png", $chat->avatar_path);
        Storage::disk('local')->assertExists($chat->avatar_path);
        $this->assertTrue($chat->avatar_next_check_at->greaterThan(now()->addDays(6)));
        $this->assertStringContainsString("/chat/avatar/{$chat->id}", $chat->avatar());
        // the expiring CDN link is never handed to the browser
        $this->assertStringNotContainsString('whatsapp.net', $chat->avatar());

        $response = $this->actingAs($this->user)->get(route('chat.avatar', $chat->id));
        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_other_users_cannot_read_the_picture(): void
    {
        $this->fakeEvolution(['profilePictureUrl' => self::CDN]);
        $chat = $this->chat();
        app(ChatAvatarService::class)->refresh($chat);
        $intruder = User::create(['name' => 'x', 'email' => 'x@t.dev', 'username' => 'x', 'password' => 'x']);

        $this->actingAs($intruder)->get(route('chat.avatar', $chat->id))->assertForbidden();
        auth()->logout();
        $this->get(route('chat.avatar', $chat->id))->assertRedirect();
    }

    public function test_chat_without_a_stored_picture_returns_404_and_null_avatar(): void
    {
        $chat = $this->chat();

        $this->assertNull($chat->avatar());
        $this->actingAs($this->user)->get(route('chat.avatar', $chat->id))->assertNotFound();
    }

    public function test_contact_without_picture_is_checked_again_only_after_a_week(): void
    {
        $this->fakeEvolution(['profilePictureUrl' => null]);
        $chat = $this->chat();

        $this->assertSame('none', app(ChatAvatarService::class)->refresh($chat));

        $chat->refresh();
        $this->assertNull($chat->avatar_path);
        $this->assertTrue($chat->avatar_next_check_at->greaterThan(now()->addDays(6)));
        $this->assertFalse(app(ChatAvatarService::class)->isDue($chat));
    }

    public function test_picture_removed_by_the_contact_deletes_the_old_file(): void
    {
        $this->fakeEvolution(['profilePictureUrl' => self::CDN]);
        $chat = $this->chat();
        app(ChatAvatarService::class)->refresh($chat);
        $old = $chat->fresh()->avatar_path;

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*/chat/fetchProfilePictureUrl/*' => Http::response([], 400)]);
        $this->assertSame('none', app(ChatAvatarService::class)->refresh($chat->fresh()));

        Storage::disk('local')->assertMissing($old);
        $this->assertNull($chat->fresh()->avatar_path);
    }

    public function test_temporary_failures_are_retried_in_minutes_not_days(): void
    {
        $chat = $this->chat();
        $service = app(ChatAvatarService::class);

        // Evolution down
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*/chat/fetchProfilePictureUrl/*' => Http::response('boom', 500)]);
        $this->assertSame('error', $service->refresh($chat));
        $next = $chat->fresh()->avatar_next_check_at;
        $this->assertTrue($next->lte(now()->addMinutes(31)));
        $this->assertNull($chat->fresh()->avatar_checked_at);

        // CDN down
        $this->fakeEvolution(['profilePictureUrl' => self::CDN], Http::response('', 503));
        $this->assertSame('error', $service->refresh($chat->fresh()));
        $this->assertTrue($chat->fresh()->avatar_next_check_at->lte(now()->addMinutes(31)));

        // and it does become due again
        $this->travel(32)->minutes();
        $this->assertTrue($service->isDue($chat->fresh()));
    }

    public function test_non_image_or_oversized_downloads_are_rejected(): void
    {
        $chat = $this->chat();
        $service = app(ChatAvatarService::class);

        $this->fakeEvolution(['profilePictureUrl' => self::CDN], Http::response('<html>', 200, ['Content-Type' => 'text/html']));
        $this->assertSame('error', $service->refresh($chat));

        $this->fakeEvolution(['profilePictureUrl' => self::CDN], Http::response(str_repeat('a', ChatAvatarService::MAX_BYTES + 1), 200, ['Content-Type' => 'image/jpeg']));
        $this->assertSame('error', $service->refresh($chat->fresh()));

        $this->fakeEvolution(['profilePictureUrl' => 'javascript:alert(1)']);
        $this->assertSame('error', $service->refresh($chat->fresh()));

        $this->assertNull($chat->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles('chat-avatars'));
    }

    public function test_queueing_is_deduplicated_and_respects_the_schedule(): void
    {
        Queue::fake();
        $service = app(ChatAvatarService::class);
        $chat = $this->chat();

        $service->queueIfDue($chat);
        $service->queueIfDue($chat); // a burst of webhooks must not pile up jobs
        Queue::assertPushed(FetchChatAvatarJob::class, 1);

        $fresh = $this->chat(['remote_jid' => '1@s.whatsapp.net', 'phone' => '1', 'avatar_next_check_at' => now()->addDay()]);
        $service->queueIfDue($fresh);
        Queue::assertPushed(FetchChatAvatarJob::class, 1);

        $noPhone = $this->chat(['remote_jid' => 'x@lid', 'phone' => null]);
        $service->queueIfDue($noPhone);
        Queue::assertPushed(FetchChatAvatarJob::class, 1);
    }

    public function test_job_fetches_the_picture(): void
    {
        $this->fakeEvolution(['profilePictureUrl' => self::CDN]);
        $chat = $this->chat();

        (new FetchChatAvatarJob($chat->id))->handle(app(ChatAvatarService::class));

        $this->assertNotNull($chat->fresh()->avatar_path);
    }

    public function test_new_chat_created_by_a_message_queues_its_picture(): void
    {
        Queue::fake();

        $chat = app(ChatMessageRecorder::class)->findOrCreateChat($this->instance, '5595888@s.whatsapp.net', 'Maria');

        Queue::assertPushed(FetchChatAvatarJob::class, fn ($j) => $j->chatId === $chat->id);
    }

    public function test_chat_list_exposes_the_local_url_and_queues_missing_pictures(): void
    {
        Queue::fake();
        $this->fakeEvolution(['profilePictureUrl' => self::CDN]);
        $with = $this->chat();
        app(ChatAvatarService::class)->refresh($with);
        $without = $this->chat(['remote_jid' => '2@s.whatsapp.net', 'phone' => '2', 'last_message_at' => now()->subMinute()]);
        Cache::flush();

        $res = $this->actingAs($this->user)->getJson(route('chat.api.chats', ['instance' => $this->instance->id]))->assertOk();

        $byId = collect($res->json('chats'))->keyBy('id');
        $this->assertStringContainsString("/chat/avatar/{$with->id}", $byId[$with->id]['avatar']);
        $this->assertNull($byId[$without->id]['avatar']);
        Queue::assertPushed(FetchChatAvatarJob::class, fn ($j) => $j->chatId === $without->id);
    }
}
