<?php

/*
| CORS is closed. This app is only called by its own pages (same origin), by HitPay (server to server)
| and by the Hermes agents (server to server, Bearer token). No browser on another site needs access,
| so no path gets any Access-Control-Allow-* header.
*/
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
