<?php

return [
    // Sandbox first: https://api.sandbox.hit-pay.com/v1   Live: https://api.hit-pay.com/v1
    'base_url' => rtrim(env('HITPAY_BASE_URL') ?: 'https://api.sandbox.hit-pay.com/v1', '/'),
    'api_key' => env('HITPAY_API_KEY'),
    // Webhook salt (HitPay dashboard > Payment Gateway > API Keys). Signatures are checked with it.
    'salt' => env('HITPAY_SALT'),
    'currency' => 'PHP',
    'timeout' => 15,
    // A payment link lasts this long (business rule 5).
    'link_minutes' => 30,
];
