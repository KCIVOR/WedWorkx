<?php

use App\Http\Controllers\Api\HermesChatController;
use App\Http\Controllers\Api\HermesOrderController;
use Illuminate\Support\Facades\Route;

// Private API for the Hermes agents. Bearer token, rate limited, paid-or-later orders only.
Route::prefix('hermes')->middleware(['throttle:hermes', 'hermes.token'])->group(function () {
    Route::get('orders', [HermesOrderController::class, 'index']);
    Route::get('orders/{reference}', [HermesOrderController::class, 'show']);
    Route::post('orders/{reference}/stage', [HermesOrderController::class, 'stage']);
    Route::post('orders/{reference}/deliver', [HermesOrderController::class, 'deliver']);

    // Supplier chats the agent runs (registration and website questions).
    Route::get('chats', [HermesChatController::class, 'index']);
    Route::get('chats/{id}', [HermesChatController::class, 'show']);
    Route::post('chats/{id}/messages', [HermesChatController::class, 'reply']);
    Route::post('chats/{id}/answers', [HermesChatController::class, 'answers']);
    Route::post('chats/{id}/ready', [HermesChatController::class, 'ready']);
});
