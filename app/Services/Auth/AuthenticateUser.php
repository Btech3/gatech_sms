<?php

namespace App\Services\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Models\User;
use App\Support\Auth\LoginAttemptResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticateUser
{
    public function attempt(string $email, string $password): LoginAttemptResult
    {
        return DB::transaction(function () use ($email, $password): LoginAttemptResult {
            $user = User::query()
                ->where('email', Str::lower(trim($email)))
                ->lockForUpdate()
                ->first();

            $canUseLocalVerificationBypass = $user !== null
                && app()->environment(['local', 'testing'])
                && config('auth.allow_unverified_login')
                && ! $user->hasVerifiedEmail();

            if ($user === null
                || (! $user->is_active && ! $canUseLocalVerificationBypass)
                || (! $user->hasVerifiedEmail() && ! $canUseLocalVerificationBypass)) {
                return new LoginAttemptResult(LoginOutcome::InvalidCredentials);
            }

            if ($user->locked_until?->isFuture()) {
                return new LoginAttemptResult(
                    LoginOutcome::Locked,
                    retryAfter: max(1, (int) now()->diffInSeconds($user->locked_until)),
                );
            }

            $failedAttempts = $user->locked_until === null
                ? $user->failed_login_attempts
                : 0;

            if (! Hash::check($password, $user->password)) {
                $failedAttempts++;
                $lockedUntil = $failedAttempts >= 5 ? now()->addMinutes(15) : null;

                $user->forceFill([
                    'failed_login_attempts' => $failedAttempts,
                    'locked_until' => $lockedUntil,
                ])->save();

                if ($lockedUntil !== null) {
                    return new LoginAttemptResult(
                        LoginOutcome::Locked,
                        retryAfter: 900,
                    );
                }

                return new LoginAttemptResult(LoginOutcome::InvalidCredentials);
            }

            if ($failedAttempts !== 0 || $user->locked_until !== null) {
                $user->forceFill([
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
            }

            return new LoginAttemptResult(
                LoginOutcome::Authenticated,
                user: $user,
            );
        });
    }
}
