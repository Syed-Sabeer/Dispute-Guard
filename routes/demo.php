<?php

declare(strict_types=1);
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TestAutomationController;
use App\Http\Middleware\LocalDemo;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('demo')->middleware([LocalDemo::class, SubstituteBindings::class])->group(function () {
    Route::get('/', DashboardController::class);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/onboarding', [SettingsController::class, 'onboarding']);
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{dispute}', [DisputeController::class, 'show']);
    Route::get('/templates', [EmailTemplateController::class, 'index']);
    Route::get('/templates/{template}/edit', [EmailTemplateController::class, 'edit']);
    Route::get('/test-automation', [TestAutomationController::class, 'index']);
    Route::get('/email-logs', [EmailLogController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'edit']);
});
