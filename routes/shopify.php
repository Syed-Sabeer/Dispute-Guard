<?php

declare(strict_types=1);
use App\Http\Controllers\ShopifyAppController;
use App\Http\Controllers\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/patch-id-token', [ShopifyAppController::class, 'patch'])->middleware('throttle:120,1');
Route::post('/webhooks/shopify/{category}/{action}', ShopifyWebhookController::class);
