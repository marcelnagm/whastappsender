<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\ServiceHours;
use App\Models\User;
use App\Services\Booking\Bookings;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** What the customer sees: pick a day and a time, leave a name and a phone. */
class PublicBookingController extends Controller
{
    public function __construct(private Bookings $bookings)
    {
    }

    public function show(Request $request, string $slug)
    {
        $cfg = $this->cfg($slug);
        $tz = ServiceHours::for($cfg->user_id)->timezone ?: config('ai.business_hours.timezone');
        $today = now()->setTimezone($tz)->startOfDay();
        $date = $today->copy();
        if ($request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date'))) {
            try {
                $date = Carbon::parse($request->query('date'), $tz)->startOfDay();
            } catch (\Throwable $e) {
            }
        }
        // days that have at least one free time, to offer only those
        $days = [];
        for ($i = 0; $i <= min($cfg->max_days, 45); $i++) {
            $d = $today->copy()->addDays($i);
            if ($this->bookings->slots($cfg, $d)) {
                $days[] = $d;
            }
        }
        if (!in_array($date->format('Y-m-d'), array_map(fn ($d) => $d->format('Y-m-d'), $days), true) && $days) {
            $date = $days[0];
        }
        $slots = array_map(fn ($s) => $s->copy()->setTimezone($tz), $days ? $this->bookings->slots($cfg, $date) : []);

        return $this->page(response()->view('booking.public', ['cfg' => $cfg, 'company' => User::find($cfg->user_id)?->name, 'days' => $days, 'date' => $date, 'slots' => $slots, 'tz' => $tz]));
    }

    public function store(Request $request, string $slug)
    {
        $cfg = $this->cfg($slug);
        $data = $request->validate(['starts_at' => 'required|date', 'name' => 'required|string|min:2|max:120', 'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{8,}$/'], 'email' => 'nullable|email|max:160']);
        $b = $this->bookings->book($cfg, $data['name'], $data['phone'], $data['email'] ?? null, Carbon::parse($data['starts_at']));
        if (!$b) {
            return back()->withErrors(['starts_at' => __('This time is no longer available. Pick another.')])->withInput();
        }

        return redirect()->route('booking-public.confirmation', $b->token);
    }

    public function confirmation(string $token)
    {
        $b = Booking::where('token', $token)->firstOrFail();
        $cfg = BookingSetting::where('user_id', $b->user_id)->firstOrFail();
        $tz = ServiceHours::for($b->user_id)->timezone ?: config('ai.business_hours.timezone');

        return $this->page(response()->view('booking.confirmation', ['b' => $b, 'cfg' => $cfg, 'company' => User::find($b->user_id)?->name, 'when' => $b->starts_at->copy()->setTimezone($tz)]));
    }

    public function cancel(string $token)
    {
        $this->bookings->cancel(Booking::where('token', $token)->firstOrFail());

        return redirect()->route('booking-public.confirmation', $token);
    }

    private function cfg(string $slug): BookingSetting
    {
        return BookingSetting::where('slug', $slug)->where('enabled', true)->firstOrFail();
    }

    private function page($response)
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
