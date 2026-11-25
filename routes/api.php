<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\EmailChangeVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Middleware\EnforceIdleSession;
use App\Http\Middleware\RequireHttps;
use Illuminate\Support\Facades\Route;

Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware([RequireHttps::class, 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::get('/v1/auth/email/change/verify/{id}/{hash}', EmailChangeVerificationController::class)
    ->middleware([RequireHttps::class, 'signed', 'throttle:6,1'])
    ->name('api.v1.auth.email-change.verify');

Route::prefix('v1/auth')
    ->middleware(RequireHttps::class)
    ->name('api.v1.auth.')
    ->group(function () {
        // Registration grants no role; authorization remains deferred to approved RBAC.
        Route::post('/register', [RegistrationController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('register');

        Route::post('/email/verification-notification', [ResendVerificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::post('/password/forgot', [PasswordRecoveryController::class, 'requestLink'])
            ->middleware('throttle:6,1')
            ->name('password.forgot');

        Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])
            ->middleware('throttle:6,1')
            ->name('password.reset');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login');

        Route::middleware(['auth:sanctum', EnforceIdleSession::class])->group(function () {
            Route::get('/user', AuthenticatedUserController::class)->name('user');
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::get('/profile/photo', [ProfileController::class, 'showPhoto'])->name('profile.photo');
            Route::put('/password', [ProfileController::class, 'changePassword'])->name('password.change');
        });
    });
