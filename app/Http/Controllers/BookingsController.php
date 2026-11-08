<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Services\Booking\Bookings;
use Illuminate\Http\Request;

/** Owner side of meeting booking: settings, public link and upcoming meetings. */
class BookingsController extends Controller
{
    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $cfg = BookingSetting::for($uid);
        $upcoming = Booking::where('user_id', $uid)->where('status', 'confirmed')->where('ends_at', '>=', now())->orderBy('starts_at')->limit(50)->get();

        return view('bookings.index', compact('cfg', 'upcoming'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120', 'description' => 'nullable|string|max:1000',
            'duration_minutes' => 'required|integer|min:10|max:240', 'min_notice_hours' => 'required|integer|min:0|max:720', 'max_days' => 'required|integer|min:1|max:180',
        ]);
        $cfg = BookingSetting::for($request->user()->id);
        $cfg->fill($data + ['user_id' => $request->user()->id, 'enabled' => $request->boolean('enabled')])->save();

        return back()->with('success', __('Saved.'));
    }

    public function cancel(Request $request, int $booking)
    {
        app(Bookings::class)->cancel(Booking::where('user_id', $request->user()->id)->findOrFail($booking));

        return back()->with('success', __('Booking canceled.'));
    }
}
