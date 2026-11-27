<?php

namespace App\Services\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\LoginAttemptResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\InternalClock;
use OTPHP\TOTP;

class MfaService
{
    private const CHALLENGE_USER_KEY = 'auth.mfa.challenge.user_id';

    private const CHALLENGE_EXPIRES_KEY = 'auth.mfa.challenge.expires_at';

    private const CHALLENGE_ENROLLMENT_KEY = 'auth.mfa.challenge.enrollment_required';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function requiresMfa(User $user): bool
    {
        $requiredRoles = config('auth.mfa.required_roles', []);

        return is_array($requiredRoles)
            && $requiredRoles !== []
            && $user->roles()->whereIn('roles.name', $requiredRoles)->exists();
    }

    public function beginChallenge(Request $request, User $user): ?int
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $retryAfter = DB::transaction(function () use ($user): ?int {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($lockedUser->mfa_locked_until?->isFuture()) {
                return max(1, (int) now()->diffInSeconds($lockedUser->mfa_locked_until));
            }

            $this->clearExpiredLockout($lockedUser);

            return null;
        });

        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $request->session()->put(self::CHALLENGE_USER_KEY, $user->getKey());
        $request->session()->put(
            self::CHALLENGE_EXPIRES_KEY,
            now()->addMinutes((int) config('auth.mfa.challenge_minutes', 5))->timestamp,
        );
        $request->session()->put(self::CHALLENGE_ENROLLMENT_KEY, ! $user->hasMfaEnabled());

        return null;
    }

    /**
     * @return array{secret: string, provisioning_uri: string, expires_at: string}
     */
    public function startEnrollment(Request $request): array
    {
        $user = $this->challengedUser($request, true);
        $pendingSecret = $user->mfa_pending_secret;
        $pendingExpiresAt = $user->mfa_pending_expires_at;

        if (! is_string($pendingSecret) || ! $pendingExpiresAt?->isFuture()) {
            $otp = TOTP::generate(new InternalClock, 20)
                ->withLabel($user->email)
                ->withIssuer((string) config('app.name'));
            $pendingSecret = $otp->getSecret();
            $pendingExpiresAt = now()->addMinutes((int) config('auth.mfa.challenge_minutes', 5));

            $user->forceFill([
                'mfa_pending_secret' => $pendingSecret,
                'mfa_pending_expires_at' => $pendingExpiresAt,
            ])->save();
        }

        $otp = TOTP::createFromSecret($pendingSecret, new InternalClock)
            ->withLabel($user->email)
            ->withIssuer((string) config('app.name'));

        return [
            'secret' => $pendingSecret,
            'provisioning_uri' => $otp->getProvisioningUri(),
            'expires_at' => $pendingExpiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{result: LoginAttemptResult, recovery_codes: list<string>}
     */
    public function confirmEnrollment(Request $request, string $code): array
    {
        $challengedUser = $this->challengedUser($request, true);
        $secret = $challengedUser->mfa_pending_secret;
        $pendingExpiresAt = $challengedUser->mfa_pending_expires_at;

        if (! is_string($secret) || ! $pendingExpiresAt?->isFuture()) {
            abort(422, 'MFA enrollment expired. Start the enrollment again.');
        }

        $matchedTimestep = $this->matchingTimestep($secret, $code);
        $recoveryCodes = $this->generateRecoveryCodes();
        $recoveryCodeHashes = array_map(
            fn (string $recoveryCode): string => Hash::make($recoveryCode),
            $recoveryCodes,
        );

        $result = DB::transaction(function () use (
            $challengedUser,
            $matchedTimestep,
            $recoveryCodeHashes,
            $request,
            $secret,
        ): LoginAttemptResult {
            $user = User::query()->lockForUpdate()->findOrFail($challengedUser->getKey());
            $this->clearExpiredLockout($user);

            if ($user->mfa_locked_until?->isFuture()) {
                return new LoginAttemptResult(
                    LoginOutcome::Locked,
                    retryAfter: max(1, (int) now()->diffInSeconds($user->mfa_locked_until)),
                );
            }

            if ($user->hasMfaEnabled()
                || $user->mfa_pending_secret !== $secret
                || $user->mfa_pending_expires_at === null
                || ! $user->mfa_pending_expires_at->isFuture()) {
                return new LoginAttemptResult(LoginOutcome::InvalidCredentials);
            }

            if ($matchedTimestep === null
                || ($user->mfa_last_used_timestep !== null
                    && $matchedTimestep <= $user->mfa_last_used_timestep)) {
                return $this->recordInvalidAttempt($user, $request);
            }

            $user->forceFill([
                'mfa_secret' => $secret,
                'mfa_enabled_at' => now(),
                'mfa_pending_secret' => null,
                'mfa_pending_expires_at' => null,
                'mfa_last_used_timestep' => $matchedTimestep,
                'mfa_failed_attempts' => 0,
                'mfa_locked_until' => null,
                'mfa_recovery_code_hashes' => $recoveryCodeHashes,
            ])->save();

            $this->auditLogger->record(
                $user,
                'Authentication',
                'mfa_enabled',
                'user',
                $user->id,
                afterValues: ['method' => 'totp'],
                request: $request,
            );

            return new LoginAttemptResult(LoginOutcome::Authenticated, user: $user);
        });

        return [
            'result' => $result,
            'recovery_codes' => $result->outcome === LoginOutcome::Authenticated ? $recoveryCodes : [],
        ];
    }

    public function verifyChallenge(Request $request, string $code): LoginAttemptResult
    {
        $challengedUser = $this->challengedUser($request, false);

        return $this->verifyTotp($challengedUser, $code, $request);
    }

    public function verifyRecoveryCode(Request $request, string $code): LoginAttemptResult
    {
        $challengedUser = $this->challengedUser($request, false);
        $normalizedCode = Str::upper($code);

        return DB::transaction(function () use ($challengedUser, $normalizedCode, $request): LoginAttemptResult {
            $user = User::query()->lockForUpdate()->findOrFail($challengedUser->getKey());
            $this->clearExpiredLockout($user);

            if ($user->mfa_locked_until?->isFuture()) {
                return new LoginAttemptResult(
                    LoginOutcome::Locked,
                    retryAfter: max(1, (int) now()->diffInSeconds($user->mfa_locked_until)),
                );
            }

            $recoveryCodeHashes = $user->mfa_recovery_code_hashes ?? [];
            $matchedIndex = null;

            foreach ($recoveryCodeHashes as $index => $recoveryCodeHash) {
                if (Hash::check($normalizedCode, $recoveryCodeHash)) {
                    $matchedIndex = $index;

                    break;
                }
            }

            if ($matchedIndex === null) {
                return $this->recordInvalidAttempt($user, $request);
            }

            array_splice($recoveryCodeHashes, $matchedIndex, 1);
            $user->forceFill([
                'mfa_recovery_code_hashes' => $recoveryCodeHashes,
                'mfa_failed_attempts' => 0,
                'mfa_locked_until' => null,
            ])->save();

            $this->auditLogger->record(
                $user,
                'Authentication',
                'mfa_recovery_code_used',
                'user',
                $user->id,
                afterValues: ['remaining_codes' => count($recoveryCodeHashes)],
                request: $request,
            );

            return new LoginAttemptResult(LoginOutcome::Authenticated, user: $user);
        });
    }

    public function clearChallenge(Request $request): void
    {
        $request->session()->forget([
            self::CHALLENGE_USER_KEY,
            self::CHALLENGE_EXPIRES_KEY,
            self::CHALLENGE_ENROLLMENT_KEY,
        ]);
    }

    private function verifyTotp(User $challengedUser, string $code, Request $request): LoginAttemptResult
    {
        $secret = $challengedUser->mfa_secret;
        $matchedTimestep = is_string($secret) ? $this->matchingTimestep($secret, $code) : null;

        return DB::transaction(function () use (
            $challengedUser,
            $matchedTimestep,
            $request,
        ): LoginAttemptResult {
            $user = User::query()->lockForUpdate()->findOrFail($challengedUser->getKey());
            $this->clearExpiredLockout($user);

            if ($user->mfa_locked_until?->isFuture()) {
                return new LoginAttemptResult(
                    LoginOutcome::Locked,
                    retryAfter: max(1, (int) now()->diffInSeconds($user->mfa_locked_until)),
                );
            }

            if (! $user->hasMfaEnabled()
                || $matchedTimestep === null
                || ($user->mfa_last_used_timestep !== null
                    && $matchedTimestep <= $user->mfa_last_used_timestep)) {
                return $this->recordInvalidAttempt($user, $request);
            }

            $user->forceFill([
                'mfa_last_used_timestep' => $matchedTimestep,
                'mfa_failed_attempts' => 0,
                'mfa_locked_until' => null,
            ])->save();

            return new LoginAttemptResult(LoginOutcome::Authenticated, user: $user);
        });
    }

    private function challengedUser(Request $request, bool $mustEnroll): User
    {
        if (! $request->hasSession()) {
            abort(401, 'A valid MFA challenge is required.');
        }

        $userId = $request->session()->get(self::CHALLENGE_USER_KEY);
        $expiresAt = $request->session()->get(self::CHALLENGE_EXPIRES_KEY);

        if (! is_numeric($userId)
            || ! is_numeric($expiresAt)
            || (int) $expiresAt <= now()->timestamp) {
            $this->clearChallenge($request);

            abort(401, 'A valid MFA challenge is required.');
        }

        $user = User::query()->find($userId);
        $enrollmentRequired = $request->session()->get(self::CHALLENGE_ENROLLMENT_KEY) === true;

        if ($user === null
            || ! $user->is_active
            || ! $user->hasVerifiedEmail()
            || ! $this->requiresMfa($user)
            || $enrollmentRequired !== ! $user->hasMfaEnabled()
            || $mustEnroll !== $enrollmentRequired) {
            $this->clearChallenge($request);

            abort(401, 'A valid MFA challenge is required.');
        }

        return $user;
    }

    private function matchingTimestep(string $secret, string $code): ?int
    {
        $period = (int) config('auth.mfa.totp_period_seconds', 30);
        $windowSteps = (int) config('auth.mfa.totp_window_steps', 1);
        $currentStep = intdiv(now()->timestamp, $period);
        $totp = TOTP::createFromSecret($secret, new InternalClock);
        $matchingSteps = [];

        for ($step = max(0, $currentStep - $windowSteps); $step <= $currentStep + $windowSteps; $step++) {
            if ($totp->verify($code, $step * $period, 0)) {
                $matchingSteps[] = $step;
            }
        }

        return $matchingSteps === [] ? null : max($matchingSteps);
    }

    private function recordInvalidAttempt(User $user, Request $request): LoginAttemptResult
    {
        $failedAttempts = (int) $user->mfa_failed_attempts + 1;
        $lockedUntil = $failedAttempts >= 5
            ? now()->addMinutes((int) config('auth.mfa.lockout_minutes', 15))
            : null;

        $user->forceFill([
            'mfa_failed_attempts' => $failedAttempts,
            'mfa_locked_until' => $lockedUntil,
        ])->save();

        if ($lockedUntil !== null) {
            $this->auditLogger->record(
                $user,
                'Authentication',
                'mfa_login_locked',
                'user',
                $user->id,
                afterValues: [
                    'failed_attempts' => $failedAttempts,
                    'locked_until' => $lockedUntil->toIso8601String(),
                ],
                request: $request,
            );

            return new LoginAttemptResult(
                LoginOutcome::Locked,
                retryAfter: max(1, (int) now()->diffInSeconds($lockedUntil)),
            );
        }

        return new LoginAttemptResult(LoginOutcome::InvalidCredentials);
    }

    private function clearExpiredLockout(User $user): void
    {
        if ($user->mfa_locked_until !== null && ! $user->mfa_locked_until->isFuture()) {
            $user->forceFill([
                'mfa_failed_attempts' => 0,
                'mfa_locked_until' => null,
            ])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($index = 0; $index < (int) config('auth.mfa.recovery_code_count', 8); $index++) {
            $codes[] = Str::upper(Str::random(32));
        }

        return $codes;
    }
}
