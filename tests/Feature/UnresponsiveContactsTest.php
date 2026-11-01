<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Contacts\UnresponsiveContacts;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class UnresponsiveContactsTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private Instance $wa;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        config(['contacts.undelivered.enabled' => true, 'contacts.undelivered.after_hours' => 72, 'contacts.undelivered.min_messages' => 1, 'contacts.undelivered.reactivate' => true]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $this->wa = Instance::create(['user_id' => $this->user->id, 'name' => 'Zap', 'instance_name' => 'zap1', 'status' => 'connected']);
        $this->receiptsArrive();
    }

    private function contact(string $number, string $status = 'ativo'): Contact
    {
        return Contact::create(['user_id' => $this->user->id, 'contact' => $number, 'name' => 'C' . $number, 'status' => $status]);
    }

    private function job(Contact $c, string $evo, float $hoursAgo, ?int $userId = null): void
    {
        $this->seq++;
        $t = now()->subMinutes((int) ($hoursAgo * 60))->toDateTimeString();
        DB::table('whatsapp_job')->insert([
            'endpoint' => 'x', 'status' => 'processado', 'message_id' => 'J' . $this->seq, 'evolution_status' => $evo, 'user_id' => $userId ?? $this->user->id,
            'contact_id' => $c->id, 'instance_id' => $this->wa->id, 'created_at' => $t, 'updated_at' => $t,
        ]);
    }

    /** a different, healthy contact proves delivery receipts are coming in for this account */
    private function receiptsArrive(): void
    {
        $this->job($this->contact('5500000000000'), 'DELIVERY_ACK', 2);
    }

    private function chatMsg(Contact $c, bool $fromMe, string $status, float $hoursAgo, string $mid): ChatMessage
    {
        $chat = Chat::where('instance_id', $this->wa->id)->where('contact_id', $c->id)->first()
            ?? Chat::create(['user_id' => $this->user->id, 'instance_id' => $this->wa->id, 'remote_jid' => $c->contact . '@s.whatsapp.net', 'phone' => $c->contact, 'contact_id' => $c->id]);

        return ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $this->wa->id, 'user_id' => $this->user->id, 'message_id' => $mid, 'from_me' => $fromMe,
            'type' => 'text', 'body' => 'x', 'status' => $status, 'message_timestamp' => now()->subMinutes((int) ($hoursAgo * 60)),
        ]);
    }

    public function test_never_delivered_after_the_period_deactivates_the_contact(): void
    {
        $dead = $this->contact('5511111111111');
        $this->job($dead, 'sent', 100);
        $fresh = $this->contact('5522222222222');
        $this->job($fresh, 'sent', 5);                       // still inside the period
        $delivered = $this->contact('5533333333333');
        $this->job($delivered, 'sent', 100);
        $this->job($delivered, 'DELIVERY_ACK', 50);          // a later message arrived
        $read = $this->contact('5544444444444');
        $this->job($read, 'read', 100);

        $r = (new UnresponsiveContacts())->run();

        $this->assertSame([$dead->id], $r['deactivated']);
        $this->assertSame('inativo', $dead->fresh()->status);
        $this->assertSame('undelivered', $dead->fresh()->deactivated_reason);
        $this->assertNotNull($dead->fresh()->deactivated_at);
        foreach ([$fresh, $delivered, $read] as $c) {
            $this->assertSame('ativo', $c->fresh()->status);
        }
    }

    public function test_period_comes_from_config_and_can_be_disabled(): void
    {
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 30);

        $this->assertSame([], (new UnresponsiveContacts())->run()['deactivated']);            // 30h < 72h
        config(['contacts.undelivered.after_hours' => 24]);
        $this->assertSame([$c->id], (new UnresponsiveContacts())->run()['deactivated']);

        $c->update(['status' => 'ativo', 'deactivated_reason' => null]);
        config(['contacts.undelivered.enabled' => false]);
        $this->assertSame([], (new UnresponsiveContacts())->run()['deactivated']);
    }

    public function test_dry_run_changes_nothing_and_min_messages_is_respected(): void
    {
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 100);

        $this->assertSame([$c->id], (new UnresponsiveContacts())->run(null, true)['deactivated']);
        $this->assertSame('ativo', $c->fresh()->status);

        config(['contacts.undelivered.min_messages' => 2]);
        $this->assertSame([], (new UnresponsiveContacts())->run()['deactivated']);
        $this->job($c, 'SERVER_ACK', 90);
        $this->assertSame([$c->id], (new UnresponsiveContacts())->run()['deactivated']);
    }

    public function test_a_contact_that_wrote_back_is_not_deactivated(): void
    {
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 100);
        $this->chatMsg($c, false, 'received', 40, 'IN1');     // wrote to us after the undelivered message

        $this->assertSame([], (new UnresponsiveContacts())->run()['deactivated']);
    }

    public function test_accounts_without_delivery_receipts_are_skipped(): void
    {
        DB::table('whatsapp_job')->where('evolution_status', 'DELIVERY_ACK')->delete();
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 100);

        $r = (new UnresponsiveContacts())->run();
        $this->assertSame([], $r['deactivated']);
        $this->assertSame([$this->user->id], $r['skipped_accounts']);
        $this->assertSame('ativo', $c->fresh()->status);
    }

    public function test_chat_messages_count_once_and_other_channels_are_ignored(): void
    {
        config(['contacts.undelivered.min_messages' => 2]);
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 100);                                       // J-id
        $this->chatMsg($c, true, 'sent', 100, 'J' . $this->seq);          // the chat echo of that same message: not counted twice
        $this->assertSame([], (new UnresponsiveContacts())->run()['deactivated']);

        $this->chatMsg($c, true, 'sent', 95, 'MANUAL1');                  // a manual one: now two distinct messages
        $this->assertSame([$c->id], (new UnresponsiveContacts())->run()['deactivated']);

        $tg = $this->contact('tg:123');
        $this->job($tg, 'sent', 100);
        config(['contacts.undelivered.min_messages' => 1]);
        (new UnresponsiveContacts())->run();
        $this->assertSame('ativo', $tg->fresh()->status);
    }

    public function test_manually_deactivated_contacts_are_left_alone_and_command_works(): void
    {
        $manual = $this->contact('5511111111111', 'inativo');
        $this->job($manual, 'sent', 100);
        $c = $this->contact('5522222222222');
        $this->job($c, 'sent', 100);

        $this->artisan('contacts:deactivate-unresponsive', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('ativo', $c->fresh()->status);
        $this->artisan('contacts:deactivate-unresponsive')->assertExitCode(0);
        $this->assertSame('inativo', $c->fresh()->status);
        $this->assertNull($manual->fresh()->deactivated_reason);
    }

    public function test_reactivation_when_the_contact_writes_or_a_receipt_arrives(): void
    {
        $c = $this->contact('5511111111111');
        $this->job($c, 'sent', 100);
        (new UnresponsiveContacts())->run();
        $this->assertSame('inativo', $c->fresh()->status);

        // the contact writes: touchChat is what every inbound message goes through
        $msg = $this->chatMsg($c, false, 'received', 0, 'IN2');
        app(ChatMessageRecorder::class)->touchChat($msg->chat, $msg, true);
        $this->assertSame('ativo', $c->fresh()->status);
        $this->assertNull($c->fresh()->deactivated_reason);

        // manual deactivations are never undone
        $c->update(['status' => 'inativo', 'deactivated_reason' => null]);
        UnresponsiveContacts::reactivate($c->id);
        $this->assertSame('inativo', $c->fresh()->status);

        // a late delivery receipt through the webhook
        $c->update(['deactivated_reason' => 'undelivered']);
        $this->job($c, 'sent', 1);
        $mid = DB::table('whatsapp_job')->where('contact_id', $c->id)->orderByDesc('id')->value('message_id');
        $payload = ['event' => 'messages.update', 'instance' => 'zap1', 'data' => ['keyId' => $mid, 'status' => 'DELIVERY_ACK']];
        (new \App\Jobs\ProcessEvolutionWebhookJob($payload))->handle();
        $this->assertSame('ativo', $c->fresh()->status);
    }
}
