<?php

use App\Http\Controllers\SenderMailboxVerificationController;
use App\Http\Controllers\ShopifyAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [ShopifyAppController::class, 'home']);
Route::get('/sender-verification', [SenderMailboxVerificationController::class, 'show'])->middleware('throttle:30,1');
Route::post('/sender-verification', [SenderMailboxVerificationController::class, 'confirm'])->middleware('throttle:10,1');
