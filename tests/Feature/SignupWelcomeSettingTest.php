<?php

namespace Tests\Feature;

use App\Http\Controllers\RegisterController;
use App\Jobs\EnviarMensagemJob;
use App\Models\AppSetting;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\Settings\SignupWelcome;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class SignupWelcomeSettingTest extends TestCase
{
    use CreatesTestSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Queue::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x']);
        $this->admin->forceFill(['role' => 'admin'])->save();
        $this->admin = $this->admin->fresh();
    }

    private function item(string $name, string $text): CampaignItem
    {
        return CampaignItem::create(['name' => $name, 'text' => $text, 'user_id' => $this->admin->id]);
    }

    private function signup(): void
    {
        $controller = new RegisterController();
        $m = new \ReflectionMethod($controller, 'generateFollowUp');
        $m->setAccessible(true);
        $m->invoke($controller, ['ddi' => '55', 'phone' => '95981110000', 'name' => 'Ana', 'email' => 'ana@example.com']);
    }

    public function test_only_administrators_open_the_screen(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x'])->fresh();

        $this->actingAs($user)->get(route('admin.welcome.edit'))->assertRedirect();
        $this->actingAs($user)->post(route('admin.welcome.update'), ['item_id' => 1])->assertRedirect();
        $this->assertFalse(AppSetting::has(SignupWelcome::KEY));

        $this->actingAs($this->admin)->get(route('admin.welcome.edit'))->assertOk()->assertSee(__('Signup welcome'));
    }

    public function test_admin_picks_the_message_and_signup_sends_it(): void
    {
        $a = $this->item('Boas-vindas A', 'Olá, seja bem-vindo!');
        $b = $this->item('Boas-vindas B', 'Oi! Obrigado por se cadastrar.');

        $this->actingAs($this->admin)->get(route('admin.welcome.edit'))->assertOk()->assertSee('Boas-vindas A')->assertSee('Boas-vindas B');
        $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => $b->id])->assertRedirect(route('admin.welcome.edit'))->assertSessionHas('success');
        $this->assertSame($b->id, app(SignupWelcome::class)->itemId());

        $this->signup();

        $job = WhatsappJob::first();
        $this->assertSame($b->id, (int) $job->campaign_item_id);
        $this->assertSame('pendente', $job->status);
        $this->assertSame('5595981110000', Contact::find($job->contact_id)->contact);
        Queue::assertPushed(EnviarMensagemJob::class, 1);

        // changing the choice applies to the next signup
        $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => $a->id]);
        $this->assertSame($a->id, app(SignupWelcome::class)->itemId());
    }

    public function test_env_is_only_the_fallback_and_none_turns_it_off(): void
    {
        $a = $this->item('A', 'Texto A');
        $b = $this->item('B', 'Texto B');
        config(['services.whatsapp.message_up' => $a->id]);

        try {
            $svc = app(SignupWelcome::class);
            $this->assertSame($a->id, $svc->itemId(), 'never saved: the .env value still works');
            $this->assertTrue($svc->usesEnvFallback());
            $this->actingAs($this->admin)->get(route('admin.welcome.edit'))->assertSee('WHATSAPP_MESSAGE_UP');

            $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => $b->id]);
            $this->assertSame($b->id, $svc->itemId(), 'the panel choice wins over .env');
            $this->assertFalse($svc->usesEnvFallback());

            $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => ''])->assertSessionHas('success');
            $this->assertNull($svc->itemId(), '"none" is an explicit choice: it is not replaced by .env');
            $this->signup();
            $this->assertSame(0, WhatsappJob::count());
            Queue::assertNothingPushed();
        } finally {
            config(['services.whatsapp.message_up' => null]);
        }
    }

    public function test_rejects_a_message_that_does_not_exist_and_a_deleted_one_does_not_break_signup(): void
    {
        $a = $this->item('A', 'Texto A');
        $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => 9999])->assertSessionHasErrors('item_id');
        $this->assertFalse(AppSetting::has(SignupWelcome::KEY));

        $this->actingAs($this->admin)->post(route('admin.welcome.update'), ['item_id' => $a->id]);
        CampaignItem::where('id', $a->id)->delete();

        $this->signup(); // logs the problem, never throws
        $this->assertSame(0, WhatsappJob::count());
    }
}
