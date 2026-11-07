<?php

return [
    // Dev mode: simulates the steps that need outside services (HitPay checkout, Hermes progress, the scheduler)
    // so the whole journey can be clicked through on a computer. false = the real services, as in production.
    // It can never be on in production: see App\Support\DevMode.
    'enabled' => filter_var(env('DEV_MODE', false), FILTER_VALIDATE_BOOLEAN),
];
