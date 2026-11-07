<?php

return [
    // Supplier photo and logo uploads (Chatbot 2). Validated again on the server.
    'max_kb' => (int) env('UPLOAD_MAX_KB', 5120),       // 5 MB per file
    'max_logo' => (int) env('UPLOAD_MAX_LOGO', 1),
    'max_photos' => (int) env('UPLOAD_MAX_PHOTOS', 10),
    'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
    'disk' => 'public',
];
