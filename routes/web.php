<?php

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

Route::get('/', function () {
    if (request()->has('shop') || request()->has('id_token')) {
        return redirect('/dashboard?'.http_build_query(request()->only(['shop', 'host', 'embedded'])));
    }

    return view('landing');
});
