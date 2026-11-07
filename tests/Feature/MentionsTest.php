<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\Mentions;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class MentionsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $maria;
    private User $joao;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono Silva', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->maria = $this->member('Maria Souza', 'm@t.dev', 'm');
        $this->joao = $this->member('João Lima', 'j@t.dev', 'j');
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'remote_jid' => 'x@s.whatsapp.net', 'name' => 'Ana']);
    }

    private function member(string $name, string $email, string $username): User
    {
        $u = User::create(['name' => $name, 'email' => $email, 'username' => $username, 'password' => 'x'])->fresh();
        $u->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();

        return $u;
    }

    public function test_note_notifies_only_mentioned_colleagues(): void
    {
        $this->actingAs($this->owner)->postJson(route('chat.api.notes.store', $this->chat->id), ['body' => 'Oi @maria veja isso, @joao-lima também. email@x.com não conta'])->assertOk();

        $this->assertSame(1, $this->maria->notifications()->count());
        $this->assertSame(1, $this->joao->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertStringContainsString('Dono Silva', $this->maria->notifications()->first()->data['message']);
    }

    public function test_author_is_not_notified_and_unknown_handles_are_ignored(): void
    {
        $n = app(Mentions::class)->mentioned($this->owner, '@dono @ninguem @maria', $this->owner->id);
        $this->assertSame([$this->maria->id], $n->pluck('id')->all());
    }

    public function test_shared_first_name_needs_the_full_handle(): void
    {
        $this->member('Maria Costa', 'mc@t.dev', 'mc');
        $this->assertCount(0, app(Mentions::class)->mentioned($this->owner, '@maria'));
        $this->assertCount(1, app(Mentions::class)->mentioned($this->owner, '@maria-souza'));
    }
}
