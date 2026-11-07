<?php

return [
    // The payment reminder email goes out once, this many hours before invite_expires_at.
    'reminder_hours_before' => (int) env('CAMPAIGN_REMINDER_HOURS', 2),

    // Registration form submissions allowed per minute per IP address (a whole booth may share one Wi-Fi address).
    'register_limit' => (int) (env('REGISTER_RATE_LIMIT') ?: 30),
];
