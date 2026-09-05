<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{ShopifyWebhookController,ShopifyAppController};
Route::get('/auth/patch-id-token',[ShopifyAppController::class,'patch'])->middleware('throttle:120,1');
Route::post('/webhooks/shopify/{category}/{action}',ShopifyWebhookController::class);
