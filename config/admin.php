<?php

return [
    // The admin lives under this path. Change it (letters, numbers, dashes) to make it harder to guess.
    'path' => trim((string) (env('ADMIN_PATH') ?: 'admin'), '/'),

    // Sign-in attempts allowed per email + IP per minute.
    'login_attempts' => (int) (env('ADMIN_LOGIN_ATTEMPTS') ?: 5),

    // Signed out after this many idle minutes.
    'idle_minutes' => (int) (env('ADMIN_IDLE_MINUTES') ?: 60),

    // Rows per page in the suppliers list.
    'per_page' => 25,

    // The most website questions any one business type may be asked (a long chat loses suppliers).
    'max_questions' => 20,

    // The most questions the registration chat may have, the 7 core ones included.
    'max_registration_questions' => 12,
];
