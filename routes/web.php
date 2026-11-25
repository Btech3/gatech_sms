<?php

use App\Http\Middleware\RequireHttps;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/v1/auth/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware(RequireHttps::class)
    ->name('api.v1.auth.csrf-cookie');
