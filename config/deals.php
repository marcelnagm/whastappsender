<?php

return [
    // A customer who writes again after this many days without writing is a new lead: with "open a deal automatically" on,
    // a new deal is created (while a deal of that person is still open, none is created)
    'new_after_days' => (int) env('DEAL_NEW_AFTER_DAYS', 30),
];
