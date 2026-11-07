<?php

namespace App\Http\Controllers;

use App\Models\ServiceHours;
use Illuminate\Http\Request;

/** Opening hours of the account and the automatic greeting / away messages. */
class ServiceHoursController extends Controller
{
    public function edit(Request $request)
    {
        return view('hours.edit', ['cfg' => ServiceHours::for($request->user()->id), 'timezones' => ['America/Sao_Paulo', 'America/Manaus', 'America/Boa_Vista', 'America/Fortaleza', 'America/Bahia', 'America/Rio_Branco', 'America/Noronha', 'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Mexico_City', 'Europe/Lisbon', 'Europe/Madrid', 'UTC']]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:64', function ($a, $v, $fail) {
                if (!in_array($v, \DateTimeZone::listIdentifiers(), true)) {
                    $fail(__('Invalid timezone.'));
                }
            }],
            'days' => 'nullable|array',
            'days.*' => 'integer|between:1,7',
            'business_start' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'business_end' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'away_enabled' => 'nullable|boolean',
            'away_message' => 'nullable|string|max:1000',
            'away_every_hours' => 'nullable|integer|min:1|max:168',
            'greeting_enabled' => 'nullable|boolean',
            'greeting_message' => 'nullable|string|max:1000',
        ]);
        if ($request->boolean('away_enabled') && trim((string) ($data['away_message'] ?? '')) === '') {
            return back()->withErrors(['away_message' => __('Type the away message.')])->withInput();
        }
        if ($request->boolean('greeting_enabled') && trim((string) ($data['greeting_message'] ?? '')) === '') {
            return back()->withErrors(['greeting_message' => __('Type the greeting message.')])->withInput();
        }

        ServiceHours::updateOrCreate(['user_id' => $request->user()->id], [
            'timezone' => $data['timezone'],
            'business_days' => implode(',', array_values(array_unique(array_map('intval', $data['days'] ?? [])))) ?: '1,2,3,4,5',
            'business_start' => $data['business_start'],
            'business_end' => $data['business_end'],
            'away_enabled' => $request->boolean('away_enabled'),
            'away_message' => $data['away_message'] ?? null,
            'away_every_hours' => (int) ($data['away_every_hours'] ?? 8),
            'greeting_enabled' => $request->boolean('greeting_enabled'),
            'greeting_message' => $data['greeting_message'] ?? null,
        ]);

        return back()->with('success', __('Opening hours saved.'));
    }
}
