<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\AI\AiAutoReplyService;
use App\Services\AI\AiBusinessHours;
use App\Services\AI\AiProfileResolver;
use App\Services\AI\Llm\LlmClient;
use App\Services\AI\Llm\LlmException;
use App\Services\AI\Llm\UrlGuard;
use App\Services\Billing\Entitlements;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class AiAgentsTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        config([
            'services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k'],
            'ai.allow_private_hosts' => false,
        ]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto']);
    }

    private function inst(string $name): Instance
    {
        return Instance::create(['user_id' => $this->user->id, 'name' => $name, 'instance_name' => $name, 'status' => 'connected']);
    }

    private function agent(array $o = []): AiProfile
    {
        return AiProfile::create($o + [
            'user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'Be brief.',
        ]);
    }

    // ---- resolver ----------------------------------------------------------------------------------------------

    public function test_resolver_prefers_instance_agent_then_default_then_legacy(): void
    {
        $a = $this->inst('a');
        $b = $this->inst('b');
        $r = new AiProfileResolver();

        $this->assertSame('groq', $r->forInstance($this->user, $a)->provider); // legacy fallback

        $default = $this->agent(['name' => 'default']);
        $this->assertSame($default->id, $r->forInstance($this->user, $a)->id);

        $specific = $this->agent(['name' => 'only a', 'instance_id' => $a->id, 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);
        $this->assertSame($specific->id, $r->forInstance($this->user, $a)->id);
        $this->assertSame($default->id, $r->forInstance($this->user, $b)->id);

        $specific->update(['enabled' => false]);
        $this->assertNull($r->forInstance($this->user, $a), 'a disabled instance agent keeps the AI silent there');
        $this->assertSame($default->id, $r->forInstance($this->user, $b)->id);
    }

    // ---- wire protocols ----------------------------------------------------------------------------------------

    public function test_openai_compatible_request_and_parsing(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => ' Olá ']]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]])]);
        $r = app(LlmClient::class)->chat($this->agent(), 'sys', [['role' => 'user', 'content' => 'oi']]);

        $this->assertSame('Olá', $r['reply']);
        $this->assertSame([3, 2], [$r['tokens_in'], $r['tokens_out']]);
        Http::assertSent(function ($req) {
            return $req->url() === 'https://api.openai.com/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer sk-secret-1234')
                && $req['messages'][0] === ['role' => 'system', 'content' => 'sys']
                && $req['temperature'] === 0.5;
        });
    }

    public function test_openai_reasoning_models_are_sent_without_temperature(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        app(LlmClient::class)->chat($this->agent(['model' => 'gpt-5-mini']), 's', [['role' => 'user', 'content' => 'x']]);
        Http::assertSent(fn ($req) => !isset($req['temperature']));
    }

    public function test_anthropic_request_merges_roles_and_parses(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]])]);
        $p = $this->agent(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'temperature' => 1.5]);
        $r = app(LlmClient::class)->chat($p, 'sys', [
            ['role' => 'assistant', 'content' => 'stale'],
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ]);

        $this->assertSame('Hi', $r['reply']);
        Http::assertSent(function ($req) {
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && $req->hasHeader('x-api-key', 'sk-secret-1234')
                && $req['system'] === 'sys'
                && $req['temperature'] === 1.0
                && $req['messages'] === [['role' => 'user', 'content' => "a\nb"]];
        });
    }

    public function test_gemini_request_and_parsing(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Oi']]]]]])]);
        $p = $this->agent(['provider' => 'gemini', 'model' => 'gemini-2.5-flash']);
        $r = app(LlmClient::class)->chat($p, 'sys', [['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => 'y'], ['role' => 'user', 'content' => 'z']]);

        $this->assertSame('Oi', $r['reply']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/models/gemini-2.5-flash:generateContent')
                && !str_contains($req->url(), 'sk-secret')
                && $req->hasHeader('x-goog-api-key', 'sk-secret-1234')
                && $req['contents'][1]['role'] === 'model';
        });
    }

    public function test_ollama_works_without_key_and_appends_v1(): void
    {
        config(['ai.allow_private_hosts' => true]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'pong']]]])]);
        $p = $this->agent(['provider' => 'ollama', 'model' => 'llama3.2', 'api_key' => null, 'base_url' => 'http://127.0.0.1:11434']);
        $this->assertSame('pong', app(LlmClient::class)->chat($p, 's', [['role' => 'user', 'content' => 'x']])['reply']);
        Http::assertSent(fn ($req) => $req->url() === 'http://127.0.0.1:11434/v1/chat/completions' && !$req->hasHeader('Authorization'));
    }

    public function test_missing_key_provider_error_and_scrubbed_key(): void
    {
        $p = $this->agent(['api_key' => null]);
        putenv('OPENAI_API_KEY');
        $this->expectException(LlmException::class);
        app(LlmClient::class)->chat($p, 's', [['role' => 'user', 'content' => 'x']]);
    }

    public function test_provider_error_never_leaks_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-secret-1234']], 401)]);
        try {
            app(LlmClient::class)->chat($this->agent(), 's', [['role' => 'user', 'content' => 'x']]);
            $this->fail('expected exception');
        } catch (LlmException $e) {
            $this->assertStringNotContainsString('sk-secret-1234', $e->getMessage());
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    // ---- SSRF guard --------------------------------------------------------------------------------------------

    public function test_url_guard(): void
    {
        foreach (['http://127.0.0.1:11434', 'http://10.0.0.5', 'http://192.168.1.2'] as $u) {
            try {
                UrlGuard::assertAllowed($u);
                $this->fail("$u should be blocked");
            } catch (LlmException $e) {
                $this->assertTrue(true);
            }
        }
        config(['ai.allow_private_hosts' => true]);
        UrlGuard::assertAllowed('http://127.0.0.1:11434');
        $this->expectException(LlmException::class);
        UrlGuard::assertAllowed('http://169.254.169.254/latest');
    }

    public function test_url_guard_rejects_non_http_schemes(): void
    {
        $this->expectException(LlmException::class);
        UrlGuard::assertAllowed('file:///etc/passwd');
    }

    // ---- business hours ----------------------------------------------------------------------------------------

    public function test_business_hours_defaults_and_per_agent_rules(): void
    {
        $tz = 'America/Sao_Paulo';
        $mon10 = Carbon::parse('2026-10-26 10:00', $tz);   // Monday
        $mon20 = Carbon::parse('2026-10-26 20:00', $tz);
        $sat10 = Carbon::parse('2026-10-31 10:00', $tz);

        $this->assertTrue(AiBusinessHours::isOpen(null, $mon10));
        $this->assertFalse(AiBusinessHours::isOpen(null, $mon20));
        $this->assertFalse(AiBusinessHours::isOpen(null, $sat10));

        $p = new AiProfile(['business_days' => '1,6', 'business_start' => '08:30', 'business_end' => '12:00', 'timezone' => $tz]);
        $this->assertTrue(AiBusinessHours::isOpen($p, $sat10->copy()->setTime(9, 0)));
        $this->assertFalse(AiBusinessHours::isOpen($p, $sat10->copy()->setTime(12, 0)));
        $this->assertFalse(AiBusinessHours::isOpen($p, $mon10->copy()->setTime(8, 29)));
        $this->assertFalse(AiBusinessHours::isOpen($p, $mon10->copy()->addDay()->setTime(9, 0)), 'Tuesday not selected');

        // timezone is honoured: 10:00 in Sao Paulo is 06:00 in Honolulu
        $p2 = new AiProfile(['timezone' => 'Pacific/Honolulu']);
        $this->assertFalse(AiBusinessHours::isOpen($p2, $mon10));

        // overnight window 18:00-02:00 on weekdays; Saturday 01:00 still belongs to Friday's shift
        $n = new AiProfile(['business_start' => '18:00', 'business_end' => '02:00', 'timezone' => $tz]);
        $this->assertTrue(AiBusinessHours::isOpen($n, Carbon::parse('2026-10-31 01:00', $tz)));
        $this->assertFalse(AiBusinessHours::isOpen($n, Carbon::parse('2026-11-01 01:00', $tz)));
    }

    // ---- end to end: inbound -> agent -> reply through the receiving instance ---------------------------------

    private function allowBilling(): void
    {
        $this->mock(Entitlements::class, function ($m) {
            $m->shouldReceive('can')->andReturn(true);
            $m->shouldReceive('allows')->andReturn(true);
            $m->shouldIgnoreMissing();
        });
    }

    private function inbound(string $instance, string $text = 'Olá'): array
    {
        return ['key' => ['remoteJid' => '5595981110695@s.whatsapp.net', 'fromMe' => false, 'id' => 'M' . uniqid()], 'message' => ['conversation' => $text]];
    }

    /** Same order as the webhook job: record the inbound text, then evaluate the auto reply. */
    private function receive(string $instance, string $text = 'Olá'): void
    {
        $data = $this->inbound($instance, $text);
        app(\App\Services\AI\AiInboundMessageRecorder::class)->record($instance, $data, $text);
        app(AiAutoReplyService::class)->handleInbound($instance, $data, $text);
    }

    public function test_reply_leaves_through_the_instance_that_received_the_message(): void
    {
        $this->allowBilling();
        $a = $this->inst('inst-a');
        $b = $this->inst('inst-b');
        Contact::create(['user_id' => $this->user->id, 'name' => 'c', 'contact' => '5595981110695']);
        $this->agent(['name' => 'default']);
        $this->agent(['name' => 'b only', 'instance_id' => $b->id, 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Resposta do B']]]),
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Resposta do A']]]]),
            'evo.test:8080/*' => Http::response(['key' => ['id' => 'OUT1']]),
        ]);

        $this->receive('inst-b');
        $this->receive('inst-a');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-b') && $req['text'] === 'Resposta do B');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-a') && $req['text'] === 'Resposta do A');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-a') && $req['text'] === 'Resposta do B');
        $this->assertSame('anthropic', AiMessage::where('direction', 'outbound')->orderBy('id')->first()->provider);
    }

    public function test_provider_failure_sends_nothing_and_records_error(): void
    {
        $this->allowBilling();
        $this->inst('inst-a');
        Contact::create(['user_id' => $this->user->id, 'name' => 'c', 'contact' => '5595981110695']);
        $this->agent();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota']], 429), 'evo.test:8080/*' => Http::response([])]);

        $this->receive('inst-a');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'sendText'));
        $err = AiMessage::where('status', 'error')->first();
        $this->assertNotNull($err);
        $this->assertStringContainsString('429', $err->error);
        $this->assertStringNotContainsString('sk-secret', (string) $err->error);
    }

    public function test_outside_business_hours_the_ai_stays_silent(): void
    {
        $this->allowBilling();
        $this->inst('inst-a');
        Contact::create(['user_id' => $this->user->id, 'name' => 'c', 'contact' => '5595981110695']);
        $this->agent(['business_hours_only' => true, 'business_days' => '1', 'business_start' => '09:00', 'business_end' => '10:00', 'timezone' => 'UTC']);
        Http::fake();

        $this->travelTo(Carbon::parse('2026-10-27 09:30', 'UTC')); // Tuesday
        app(AiAutoReplyService::class)->handleInbound('inst-a', $this->inbound('inst-a'), 'Olá');

        Http::assertNothingSent();
        $this->assertSame(0, AiMessage::where('direction', 'outbound')->count());
    }

    // ---- settings pages ----------------------------------------------------------------------------------------

    private function payload(array $o = []): array
    {
        return $o + ['name' => 'Vendas', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-new-9999', 'temperature' => 0.4,
            'max_tokens' => 300, 'system_prompt' => 'Seja gentil', 'enabled' => 1, 'business_hours_only' => 1, 'business_days' => [1, 2, 3],
            'business_start' => '08:00', 'business_end' => '17:30', 'timezone' => 'America/Boa_Vista'];
    }

    public function test_create_agent_stores_encrypted_key_and_hours(): void
    {
        $inst = $this->inst('inst-a');
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['instance_id' => $inst->id]))->assertRedirect(route('ai.index'));

        $p = AiProfile::firstOrFail();
        $this->assertSame('sk-new-9999', $p->api_key);
        $this->assertNotSame('sk-new-9999', $p->getRawOriginal('api_key'));
        $this->assertSame('1,2,3', $p->business_days);
        $this->assertSame('17:30', $p->business_end);
        $this->assertSame($inst->id, $p->instance_id);
    }

    public function test_key_is_kept_when_blank_and_never_rendered(): void
    {
        $p = $this->agent();
        $this->actingAs($this->user)->put(route('ai.update', $p), $this->payload(['api_key' => '', 'name' => 'Renamed']))->assertRedirect();
        $this->assertSame('sk-secret-1234', $p->fresh()->api_key);
        $this->assertSame('Renamed', $p->fresh()->name);

        $html = $this->actingAs($this->user)->get(route('ai.edit', $p))->assertOk()->getContent();
        $this->assertStringNotContainsString('sk-secret-1234', $html);
        $this->assertStringContainsString('••••1234', $html);
        $this->assertStringNotContainsString('sk-secret-1234', $this->actingAs($this->user)->get(route('ai.index'))->getContent());
    }

    public function test_only_one_agent_per_instance_and_one_for_any(): void
    {
        $inst = $this->inst('inst-a');
        $this->agent(['instance_id' => $inst->id]);
        $this->agent(['name' => 'any']);

        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['instance_id' => $inst->id]))->assertSessionHasErrors('instance_id');
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload())->assertSessionHasErrors('instance_id');
        $this->assertSame(2, AiProfile::count());
    }

    public function test_ownership_is_enforced(): void
    {
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $theirs = AiProfile::create(['user_id' => $other->id, 'name' => 'x', 'provider' => 'groq', 'model' => 'm']);
        $foreign = Instance::create(['user_id' => $other->id, 'name' => 'f', 'instance_name' => 'f', 'status' => 'connected']);

        $this->actingAs($this->user)->get(route('ai.edit', $theirs))->assertForbidden();
        $this->actingAs($this->user)->put(route('ai.update', $theirs), $this->payload())->assertForbidden();
        $this->actingAs($this->user)->delete(route('ai.destroy', $theirs))->assertForbidden();
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['instance_id' => $foreign->id]))->assertSessionHasErrors('instance_id');
        $this->assertDatabaseHas('ai_profiles', ['id' => $theirs->id]);
    }

    public function test_custom_provider_requires_url_and_validation_rejects_unknown_provider(): void
    {
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['provider' => 'custom', 'base_url' => '']))->assertSessionHasErrors('base_url');
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['provider' => 'nope']))->assertSessionHasErrors('provider');
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['business_start' => '25:99']))->assertSessionHasErrors('business_start');
        $this->actingAs($this->user)->post(route('ai.store'), $this->payload(['timezone' => 'Mars/Base']))->assertSessionHasErrors('timezone');
    }

    public function test_master_toggle_sets_enabled_and_mode(): void
    {
        $this->user->forceFill(['ai_enabled' => false, 'ai_mode' => 'off'])->save();
        $this->actingAs($this->user)->post(route('ai.toggle'), ['enabled' => 1]);
        $this->assertTrue((bool) $this->user->fresh()->ai_enabled);
        $this->assertSame('auto', $this->user->fresh()->ai_mode);
        $this->actingAs($this->user)->post(route('ai.toggle'), ['enabled' => 0]);
        $this->assertFalse((bool) $this->user->fresh()->ai_enabled);
    }

    public function test_connection_test_endpoint_reuses_saved_key(): void
    {
        $p = $this->agent();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'OK']]]])
            ->push(['error' => ['message' => 'nope']], 401)]);

        $this->actingAs($this->user)->postJson(route('ai.test'), $this->payload(['api_key' => '', 'profile_id' => $p->id]))
            ->assertOk()->assertJson(['ok' => true, 'reply' => 'OK']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer sk-secret-1234') && ($req['max_tokens'] ?? $req['max_completion_tokens'] ?? null) === 40);

        $this->actingAs($this->user)->postJson(route('ai.test'), $this->payload())->assertOk()->assertJson(['ok' => false]);
    }

    public function test_legacy_api_fields_sync_into_default_agent(): void
    {
        $p = $this->agent(['provider' => 'groq', 'model' => 'old']);
        $this->user->forceFill(['ai_system_prompt' => 'novo', 'ai_model' => 'llama-3.1-8b-instant', 'ai_max_tokens' => 333, 'ai_business_hours_only' => true])->save();
        AiProfile::syncFromUser($this->user->fresh());

        $p->refresh();
        $this->assertSame('novo', $p->system_prompt);
        $this->assertSame('llama-3.1-8b-instant', $p->model);
        $this->assertSame(333, $p->max_tokens);
        $this->assertTrue($p->business_hours_only);
    }

    public function test_migration_copies_legacy_settings(): void
    {
        \Illuminate\Support\Facades\Schema::drop('ai_profiles');
        $u = User::create(['name' => 'l', 'email' => 'l@t.dev', 'username' => 'l', 'password' => 'x', 'ai_enabled' => true, 'ai_system_prompt' => 'legacy prompt', 'ai_model' => 'llama-3.1-8b-instant']);
        (require database_path('migrations/2026_10_30_100000_create_ai_profiles_table.php'))->up();

        $p = AiProfile::where('user_id', $u->id)->firstOrFail();
        $this->assertSame('groq', $p->provider);
        $this->assertSame('legacy prompt', $p->system_prompt);
        $this->assertNull($p->instance_id);
    }
}
