<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\Birthdays;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class BirthdaysTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function contact(string $name, ?string $birthday, array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->owner->id, 'name' => $name, 'contact' => '55119' . random_int(10000000, 99999999), 'birthday' => $birthday]);
    }

    public function test_run_fires_once_a_year_for_todays_birthdays_only(): void
    {
        $today = Carbon::parse('2026-11-08 08:00:00');
        $ana = $this->contact('Ana', '1990-11-08');
        $this->contact('Bia', '1991-11-09');
        $this->contact('Caio', '1992-11-08', ['ignore_me' => 1]);   // opted out: not contacted
        $this->contact('Sem data', null);
        $svc = app(Birthdays::class);

        $this->assertSame(1, $svc->run($today));
        $this->assertSame(2026, $ana->fresh()->birthday_notified_year);
        $this->assertSame(0, $svc->run($today));   // a second run the same day does nothing
        $this->assertSame(1, $svc->run($today->copy()->addYear()->setDate(2027, 11, 8)));   // next year again
    }

    public function test_leap_day_is_celebrated_on_feb_28_in_common_years(): void
    {
        $this->contact('Leo', '2000-02-29');
        $this->assertSame(1, app(Birthdays::class)->run(Carbon::parse('2027-02-28')));
    }

    public function test_upcoming_lists_next_days_across_the_year_end(): void
    {
        $this->contact('Hoje', '1990-12-28');
        $this->contact('Janeiro', '1985-01-03');
        $this->contact('Longe', '1985-07-01');
        $rows = app(Birthdays::class)->upcoming($this->owner, 30, Carbon::parse('2026-12-28 10:00:00'));
        $this->assertSame(['Hoje', 'Janeiro'], $rows->map(fn ($r) => $r['contact']->name)->all());
        $this->assertSame([0, 6], $rows->pluck('in_days')->all());
        $this->assertSame(36, $rows[0]['turns']);
    }

    public function test_page_and_form_field(): void
    {
        $c = $this->contact('Ana', now()->addDays(2)->subYears(30)->toDateString());
        $this->actingAs($this->owner)->get(route('contacts.birthdays'))->assertOk()->assertSee('Ana');
        $this->actingAs($this->owner)->put(route('contacts.update', $c->id), ['name' => 'Ana', 'contact' => $c->contact, 'birthday' => '1991-03-04'])->assertRedirect();
        $this->assertSame('1991-03-04', $c->fresh()->birthday->toDateString());
        $this->assertContains('contact.birthday', \App\Services\Automation\Automations::TRIGGERS);
    }
}
