<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{DashboardController,DisputeController,EmailTemplateController,EmailLogController,SettingsController,TestAutomationController,BillingController,PrivacyRequestController};
use App\Http\Middleware\{AuthenticateShopify,EmbeddedCsrf,EnsureActiveSubscription};
Route::middleware([AuthenticateShopify::class,EmbeddedCsrf::class,'throttle:merchant'])->group(function () {
    Route::get('/dashboard',DashboardController::class)->name('dashboard');
    Route::get('/onboarding',[SettingsController::class,'onboarding']);
    Route::get('/disputes',[DisputeController::class,'index']);
    Route::get('/disputes/{dispute}',[DisputeController::class,'show']);
    Route::post('/disputes/{dispute}/resync',[DisputeController::class,'resync']);
    Route::get('/templates',[EmailTemplateController::class,'index']);
    Route::get('/templates/{template}/edit',[EmailTemplateController::class,'edit']);
    Route::put('/templates/{template}',[EmailTemplateController::class,'update']);
    Route::post('/templates/{template}/restore',[EmailTemplateController::class,'restore']);
    Route::post('/templates/{template}/preview',[EmailTemplateController::class,'preview']);
    Route::get('/test-automation',[TestAutomationController::class,'index']);
    Route::post('/test-automation/send',[TestAutomationController::class,'send'])->middleware('throttle:test-mail');
    Route::get('/email-logs',[EmailLogController::class,'index']);
    Route::get('/email-logs/{log}',[EmailLogController::class,'show']);
    Route::get('/settings',[SettingsController::class,'edit']);
    Route::put('/settings',[SettingsController::class,'update'])->middleware(EnsureActiveSubscription::class);
    Route::get('/billing',BillingController::class);
    Route::get('/privacy-requests',[PrivacyRequestController::class,'index']);
    Route::get('/privacy-requests/{privacyRequest}/export',[PrivacyRequestController::class,'export']);
    Route::post('/privacy-requests/{privacyRequest}/complete',[PrivacyRequestController::class,'complete']);
});
