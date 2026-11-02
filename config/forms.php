<?php

return [
    // Prefix added to phone numbers typed without a country code (digits only, e.g. 55 = Brazil). Empty = leave as typed
    'default_country_code' => env('FORMS_DEFAULT_COUNTRY_CODE', '55'),

    // Submissions accepted per minute from one IP address (spam protection of the public endpoint)
    'throttle_per_minute' => (int) env('FORMS_THROTTLE_PER_MINUTE', 10),

    // Most fields one form may have
    'max_fields' => 20,
];
