<?php

return [
    // Bearer token the Hermes agents send. Empty means the API rejects every request.
    'token' => env('HERMES_API_TOKEN'),

    // Requests per minute per client IP, counted before the token is checked.
    'rate_limit' => (int) env('HERMES_RATE_LIMIT', 120),

    // The only supplier statuses Hermes may see or touch (business rule 11).
    'visible_statuses' => ['paid', 'building', 'delivered'],
];
