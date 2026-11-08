<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\Contact;
use App\Models\ServiceHours;
use App\Models\Task;
use App\Models\User;
use App\Services\Booking\Bookings;
use Carbon\Carbon;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private BookingSetting $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00:00', 'UTC')); // Monday
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        ServiceHours::create(['user_id' => $this->owner->id, 'timezone' => 'UTC', 'business_days' => '1,2,3,4,5', 'business_start' => '09:00', 'business_end' => '12:00', 'away_every_hours' => 8]);
        $this->cfg = BookingSetting::create(['user_id' => $this->owner->id, 'enabled' => true, 'slug' => 'dono', 'title' => 'Demo', 'duration_minutes' => 60, 'min_notice_hours' => 2, 'max_days' => 14]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function times(string $day): array
    {
        return array_map(fn ($s) => $s->format('H:i'), app(Bookings::class)->slots($this->cfg, Carbon::parse($day, 'UTC')));
    }

    public function test_slots_follow_hours_notice_and_weekends(): void
    {
        $this->assertSame(['10:00', '11:00'], $this->times('2026-11-09'));   // 09:00 is inside the 2h notice
        $this->assertSame(['09:00', '10:00', '11:00'], $this->times('2026-11-10'));
        $this->assertSame([], $this->times('2026-11-14'));                    // Saturday
        $this->assertSame([], $this->times('2026-12-30'));                    // beyond max days
    }

    public function test_booking_creates_contact_task_notice_and_blocks_the_slot(): void
    {
        $at = Carbon::parse('2026-11-10 10:00:00', 'UTC');
        $b = app(Bookings::class)->book($this->cfg, 'Ana Souza', '(95) 99999-0000', 'a@x.com', $at);

        $this->assertNotNull($b);
        $this->assertSame(['09:00', '11:00'], $this->times('2026-11-10'));
        $this->assertSame('Ana Souza', Contact::first()->name);
        $this->assertSame('Demo: Ana Souza', Task::first()->title);
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertNull(app(Bookings::class)->book($this->cfg, 'Bob', '95988880000', null, $at)); // taken
        $this->assertNull(app(Bookings::class)->book($this->cfg, 'Bob', '95988880000', null, Carbon::parse('2026-11-10 13:00:00', 'UTC'))); // outside hours
    }

    public function test_cancel_frees_the_slot_and_removes_the_open_task(): void
    {
        $b = app(Bookings::class)->book($this->cfg, 'Ana', '95999990000', null, Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        app(Bookings::class)->cancel($b);
        $this->assertContains('09:00', $this->times('2026-11-10'));
        $this->assertSame(0, Task::count());
    }

    public function test_public_flow(): void
    {
        $this->get(route('booking-public.show', 'dono'))->assertOk()->assertSee('Demo');
        $r = $this->post(route('booking-public.store', 'dono'), ['starts_at' => '2026-11-10T10:00:00+00:00', 'name' => 'Ana Souza', 'phone' => '95999990000']);
        $b = Booking::first();
        $r->assertRedirect(route('booking-public.confirmation', $b->token));
        $this->get(route('booking-public.confirmation', $b->token))->assertOk()->assertSee('10/11/2026 10:00');
        $this->post(route('booking-public.store', 'dono'), ['starts_at' => '2026-11-10T10:00:00+00:00', 'name' => 'Bob', 'phone' => '95988880000'])->assertSessionHasErrors('starts_at');
        $this->post(route('booking-public.cancel', $b->token))->assertRedirect();
        $this->assertSame('canceled', $b->fresh()->status);
    }

    public function test_disabled_page_and_bad_phone(): void
    {
        $this->post(route('booking-public.store', 'dono'), ['starts_at' => '2026-11-10T10:00:00+00:00', 'name' => 'Ana', 'phone' => 'abc'])->assertSessionHasErrors('phone');
        $this->cfg->update(['enabled' => false]);
        $this->get(route('booking-public.show', 'dono'))->assertNotFound();
    }

    public function test_admin_page(): void
    {
        $this->actingAs($this->owner)->get(route('bookings.index'))->assertOk();
        $this->actingAs($this->owner)->put(route('bookings.update'), ['title' => 'Call', 'duration_minutes' => 45, 'min_notice_hours' => 1, 'max_days' => 20, 'enabled' => 1])->assertRedirect();
        $this->assertSame(45, $this->cfg->fresh()->duration_minutes);
        $b = app(Bookings::class)->book($this->cfg->fresh(), 'Ana', '95999990000', null, Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->post(route('bookings.cancel', $b->id))->assertNotFound();
        $this->actingAs($this->owner)->post(route('bookings.cancel', $b->id))->assertRedirect();
        $this->assertSame('canceled', $b->fresh()->status);
    }
}
