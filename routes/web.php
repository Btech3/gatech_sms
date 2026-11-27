<?php

use App\Http\Middleware\RequireHttps;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs/api', 'docs.api')
    ->middleware(RequireHttps::class)
    ->name('api.docs.ui');

Route::get('/docs/api/openapi.yaml', function () {
    return response()->file(base_path('docs/api/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=utf-8',
        'Content-Disposition' => 'inline; filename="openapi.yaml"',
    ]);
})->middleware(RequireHttps::class)
    ->name('api.docs.openapi');

Route::get('/api/v1/auth/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware(RequireHttps::class)
    ->name('api.v1.auth.csrf-cookie');
