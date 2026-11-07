<?php

return [
    // Who asks each chat's questions: "system" (the fixed questions) or "hermes" (the AI agent, through the chat relay).
    // Saved in Admin > Settings; these .env values are the defaults.
    'mode' => [
        'registration' => env('CHAT_MODE_REGISTRATION', 'system'),
        'website' => env('CHAT_MODE_WEBSITE', 'system'),
    ],

    // Seconds the supplier waits for Hermes (to start, or to answer a message) before the fixed chat quietly takes over.
    'reply_timeout' => (int) env('AGENT_CHAT_REPLY_TIMEOUT', 25),

    // Hermes counts as online if it asked for waiting chats in the last this-many seconds.
    'online_window' => (int) env('AGENT_CHAT_ONLINE_WINDOW', 45),

    // The longest a Hermes request may be held open waiting for a chat (seconds). The API accepts 0 to 20; this caps it.
    'wait_max' => (int) env('AGENT_CHAT_WAIT_MAX', 20),

    // Only one consumer (the plugin or the bridge) answers chats at a time. A consumer keeps the lease by asking
    // again within this many seconds; after that another consumer may take over.
    'lease_seconds' => (int) env('AGENT_CHAT_LEASE_SECONDS', 30),

    'max_messages' => 80,
    'max_length' => 1000,

    // A chat that has not finished after this many hours is dropped (the supplier uses the fixed chat).
    'expire_hours' => 24,
];
