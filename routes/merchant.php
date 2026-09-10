<?php

declare(strict_types=1);
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailSenderController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\PrivacyRequestController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TestAutomationController;
use App\Http\Middleware\AuthenticateShopify;
use App\Http\Middleware\EmbeddedCsrf;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureTestToolsEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateShopify::class, EmbeddedCsrf::class, 'throttle:merchant'])->group(function () {
    Route::get('/settings/email-sender', [EmailSenderController::class, 'index']);
    Route::put('/settings/email-sender', [EmailSenderController::class, 'save'])->middleware(['throttle:sender-domain', 'throttle:sender-create']);
    Route::post('/settings/email-sender/verify', [EmailSenderController::class, 'verify'])->middleware('throttle:sender-domain');
    Route::post('/settings/email-sender/resend', [EmailSenderController::class, 'resend'])->middleware(['throttle:sender-domain', 'throttle:sender-create']);
    Route::post('/settings/email-sender/disconnect', [EmailSenderController::class, 'disconnect'])->middleware('throttle:sender-domain');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/onboarding', [SettingsController::class, 'onboarding']);
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{dispute}', [DisputeController::class, 'show']);
    Route::post('/disputes/{dispute}/resync', [DisputeController::class, 'resync']);
    Route::get('/templates', [EmailTemplateController::class, 'index']);
    Route::get('/templates/{template}/edit', [EmailTemplateController::class, 'edit']);
    Route::put('/templates/{template}', [EmailTemplateController::class, 'update']);
    Route::post('/templates/{template}/restore', [EmailTemplateController::class, 'restore']);
    Route::post('/templates/{template}/preview', [EmailTemplateController::class, 'preview']);
    Route::get('/test-automation', [TestAutomationController::class, 'index'])->middleware(EnsureTestToolsEnabled::class);
    Route::post('/test-automation/send', [TestAutomationController::class, 'send'])->middleware([EnsureTestToolsEnabled::class, 'throttle:test-mail']);
    Route::get('/email-logs', [EmailLogController::class, 'index']);
    Route::get('/email-logs/{log}', [EmailLogController::class, 'show']);
    Route::get('/settings', [SettingsController::class, 'edit']);
    Route::put('/settings', [SettingsController::class, 'update'])->middleware(EnsureActiveSubscription::class);
    Route::get('/billing', BillingController::class);
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::get('/privacy-requests', [PrivacyRequestController::class, 'index']);
    Route::get('/privacy-requests/{privacyRequest}/export', [PrivacyRequestController::class, 'export']);
    Route::post('/privacy-requests/{privacyRequest}/complete', [PrivacyRequestController::class, 'complete']);
});
