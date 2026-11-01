<?php

/*
|--------------------------------------------------------------------------
| Contacts: unresponsive-contact housekeeping and scoring
|--------------------------------------------------------------------------
*/
return [
    'undelivered' => [
        // Turn the automatic deactivation off/on
        'enabled' => (bool) env('CONTACT_UNDELIVERED_ENABLED', true),

        // A WhatsApp message that was accepted by WhatsApp ("sent") but never reached the phone ("delivered"/"read")
        // after this many hours counts as undelivered
        'after_hours' => max(1, (int) env('CONTACT_UNDELIVERED_AFTER_HOURS', 72)),

        // How many such messages (and no sign of life) deactivate the contact
        'min_messages' => max(1, (int) env('CONTACT_UNDELIVERED_MIN_MESSAGES', 1)),

        // Only messages from the last N days are considered
        'lookback_days' => max(1, (int) env('CONTACT_UNDELIVERED_LOOKBACK_DAYS', 30)),

        // Safety: act only for accounts whose delivery receipts are really arriving (at least one "delivered"/"read"
        // in the last N days). Without receipts every message would look undelivered.
        'evidence_days' => max(1, (int) env('CONTACT_UNDELIVERED_EVIDENCE_DAYS', 30)),

        // Put the contact back to "ativo" when it writes to us or a delivery receipt finally arrives
        'reactivate' => (bool) env('CONTACT_UNDELIVERED_REACTIVATE', true),
    ],

    'score' => [
        // Automatic 0-100 engagement score (a score typed by a person locks that contact)
        'enabled' => (bool) env('CONTACT_SCORE_ENABLED', true),

        // Activity older than this many days stops counting
        'window_days' => max(7, (int) env('CONTACT_SCORE_WINDOW_DAYS', 90)),

        // Recalculate a contact at most this often, unless it had activity since
        'refresh_hours' => max(1, (int) env('CONTACT_SCORE_REFRESH_HOURS', 24)),

        // Ranking tiers: score >= hot -> hot, >= warm -> warm, >= cold -> cold, otherwise frozen
        'tiers' => [
            'hot' => (int) env('CONTACT_SCORE_HOT', 70),
            'warm' => (int) env('CONTACT_SCORE_WARM', 40),
            'cold' => (int) env('CONTACT_SCORE_COLD', 15),
        ],
    ],
];
