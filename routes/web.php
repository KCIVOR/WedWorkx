<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PaymentHistoryController as AdminPaymentHistoryController;
use App\Http\Controllers\Admin\QuestionController as AdminQuestionController;
use App\Http\Controllers\Admin\RegistrationQuestionController as AdminRegistrationQuestionController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\SupplierController as AdminSupplierController;
use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\AiWordingController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\HitPayWebhookController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MyWebsiteController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// QR landing page and Chatbot 1.
Route::get('/', [RegistrationController::class, 'show'])->name('home');
Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:register')->name('register');

// Friendlier question wording (optional, falls back to the scripted text). Only a question key is accepted.
Route::post('/ai/reword', [AiWordingController::class, 'registration'])->middleware('throttle:40,1')->name('ai.reword.registration');
Route::post('/invite/{token}/ai/reword', [AiWordingController::class, 'website'])->middleware('throttle:40,1')->name('ai.reword.website');

// Chats the Hermes agent runs. The browser only relays the supplier's words; if Hermes is not there, start answers fallback.
Route::post('/agent-chat/registration/start', [AgentChatController::class, 'startRegistration'])->middleware('throttle:agent-start')->name('agent.start.registration');
Route::post('/invite/{token}/agent-chat/start', [AgentChatController::class, 'startWebsite'])->middleware('throttle:agent-start')->name('agent.start.website');
Route::post('/agent-chat/{id}/messages', [AgentChatController::class, 'send'])->middleware('throttle:agent-send')->name('agent.send');
Route::get('/agent-chat/{id}', [AgentChatController::class, 'show'])->middleware('throttle:agent-poll')->name('agent.show');
Route::post('/agent-chat/{id}/fallback', [AgentChatController::class, 'fallback'])->middleware('throttle:agent-end')->name('agent.fallback');
Route::post('/agent-chat/{id}/finish', [AgentChatController::class, 'finish'])->middleware('throttle:agent-end')->name('agent.finish');

// Private invite link from the invitation email: Chatbot 2 and uploads.
Route::get('/invite/{token}', [InviteController::class, 'show'])->name('invite.show');
Route::get('/invite/{token}/my-website', [MyWebsiteController::class, 'show'])->name('invite.my-website');
Route::post('/invite/{token}/confirm', [InviteController::class, 'confirm'])->name('invite.confirm');
Route::post('/invite/{token}/uploads', [UploadController::class, 'store'])->middleware('throttle:60,1')->name('invite.uploads.store');
Route::delete('/invite/{token}/uploads/{upload}', [UploadController::class, 'destroy'])->middleware('throttle:60,1')->name('invite.uploads.destroy');

// Payment (HitPay). The browser return only shows status; the webhook is the only thing that marks paid.
Route::post('/invite/{token}/pay', [PaymentController::class, 'start'])->middleware('throttle:20,1')->name('invite.pay');
Route::get('/invite/{token}/payment/return', [PaymentController::class, 'back'])->name('invite.payment.return');
Route::post('/webhooks/hitpay', HitPayWebhookController::class)->middleware('throttle:120,1')->name('webhooks.hitpay');

// Dev mode (DEV_MODE=true, never in production): stand-ins for HitPay, Hermes and the scheduler. 404 when off.
Route::middleware('dev.mode')->prefix('dev')->group(function () {
    Route::get('/checkout/{requestId}', [DevController::class, 'checkout'])->name('dev.checkout');
    Route::post('/checkout/{requestId}/{outcome}', [DevController::class, 'complete'])->name('dev.checkout.complete');
    Route::post('/invite/{token}/advance', [DevController::class, 'advance'])->name('dev.advance');
    Route::post('/invite/{token}/expire', [DevController::class, 'expire'])->name('dev.expire');
    Route::post('/invite/{token}/remind', [DevController::class, 'remind'])->name('dev.remind');
});

// Admin: suppliers, progress, payments and the settings (SMTP, HitPay, Hermes, AI). Path is ADMIN_PATH (default /admin).
Route::prefix(config('admin.path'))->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'show'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:30,1')->name('login.attempt');

    Route::middleware('admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/payments', [AdminPaymentHistoryController::class, 'index'])->name('payments');

        Route::get('/suppliers', [AdminSupplierController::class, 'index'])->name('suppliers');
        Route::get('/suppliers/export', [AdminSupplierController::class, 'export'])->middleware('throttle:20,1')->name('suppliers.export');
        Route::get('/suppliers/{supplier}', [AdminSupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
        Route::post('/suppliers/{supplier}/emails/{emailLog}/resend', [AdminSupplierController::class, 'resendEmail'])
            ->whereNumber(['supplier', 'emailLog'])->middleware('throttle:20,1')->name('suppliers.emails.resend');

        Route::get('/questions', [AdminQuestionController::class, 'index'])->name('questions');
        // Registration chat (Chatbot 1) questions.
        Route::post('/questions/registration', [AdminRegistrationQuestionController::class, 'store'])->middleware('throttle:60,1')->name('questions.registration.store');
        Route::post('/questions/registration/reorder', [AdminRegistrationQuestionController::class, 'reorder'])->middleware('throttle:60,1')->name('questions.registration.reorder');
        Route::put('/questions/registration/{registrationQuestion}', [AdminRegistrationQuestionController::class, 'update'])->whereNumber('registrationQuestion')->middleware('throttle:60,1')->name('questions.registration.update');
        Route::delete('/questions/registration/{registrationQuestion}', [AdminRegistrationQuestionController::class, 'destroy'])->whereNumber('registrationQuestion')->middleware('throttle:60,1')->name('questions.registration.destroy');
        Route::post('/questions/registration/{id}/restore', [AdminRegistrationQuestionController::class, 'restore'])->whereNumber('id')->middleware('throttle:60,1')->name('questions.registration.restore');
        // Website chat (Chatbot 2) questions.
        Route::post('/questions', [AdminQuestionController::class, 'store'])->middleware('throttle:60,1')->name('questions.store');
        Route::post('/questions/reorder', [AdminQuestionController::class, 'reorder'])->middleware('throttle:60,1')->name('questions.reorder');
        Route::put('/questions/{question}', [AdminQuestionController::class, 'update'])->whereNumber('question')->middleware('throttle:60,1')->name('questions.update');
        Route::delete('/questions/{question}', [AdminQuestionController::class, 'destroy'])->whereNumber('question')->middleware('throttle:60,1')->name('questions.destroy');
        Route::post('/questions/{id}/restore', [AdminQuestionController::class, 'restore'])->whereNumber('id')->middleware('throttle:60,1')->name('questions.restore');

        Route::get('/settings', [AdminSettingsController::class, 'show'])->name('settings');
        Route::post('/settings/campaign', [AdminSettingsController::class, 'campaign'])->name('settings.campaign');
        Route::post('/settings/hermes/token', [AdminSettingsController::class, 'hermesToken'])->name('settings.hermes.token');
        Route::delete('/settings/hermes/token', [AdminSettingsController::class, 'clearHermesToken'])->name('settings.hermes.clear');
        Route::post('/settings/test/{what}', [AdminSettingsController::class, 'test'])->middleware('throttle:10,1')->name('settings.test');
        Route::post('/settings/{group}', [AdminSettingsController::class, 'update'])->name('settings.update');

        Route::get('/activity', AdminAuditController::class)->name('activity');
    });
});

// T1 design proof page. Local and test only: it is a development aid and has no place on the live site.
if (! app()->isProduction()) {
    Route::get('/design-check', fn () => Inertia::render('DesignCheck'));
}
