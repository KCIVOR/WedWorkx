<?php

/**
 * Child process used by ConcurrentRegistrationTest.
 * Usage: php register_worker.php <index> <startAtUnixFloat>
 * Boots the app, waits for the shared start time, registers one supplier, prints the outcome as JSON.
 */

use App\Services\SlotService;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $i, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$result = $app->make(SlotService::class)->register([
    'name' => "Supplier $i",
    'email' => "supplier$i@example.com",
    'mobile' => '+6391700'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
    'business_name' => "Business $i",
    'business_type' => 'photographer',
    'location' => 'Tagaytay City, Cavite',
    'social_link' => null,
]);

echo json_encode([
    'outcome' => $result['outcome'],
    'slot' => $result['supplier']?->slot_number,
    'waitlist' => $result['supplier']?->waitlist_number,
]);
