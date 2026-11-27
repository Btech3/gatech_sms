<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyMfaCodeRequest;
use App\Http\Requests\Api\V1\Auth\VerifyMfaRecoveryCodeRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use App\Services\Auth\AuthenticatedSessionManager;
use App\Services\Auth\MfaService;
use App\Support\Auth\LoginAttemptResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class MfaController extends Controller
{
    public function startEnrollment(Request $request, MfaService $mfa): JsonResponse
    {
        return response()->json([
            'data' => $mfa->startEnrollment($request),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function confirmEnrollment(
        VerifyMfaCodeRequest $request,
        MfaService $mfa,
        AuthenticatedSessionManager $sessions,
    ): JsonResponse {
        $enrollment = $mfa->confirmEnrollment(
            $request,
            $request->string('code')->toString(),
        );

        return $this->completeMfaLogin(
            $request,
            $enrollment['result'],
            $mfa,
            $sessions,
            ['recovery_codes' => $enrollment['recovery_codes']],
        );
    }

    public function verify(
        VerifyMfaCodeRequest $request,
        MfaService $mfa,
        AuthenticatedSessionManager $sessions,
    ): JsonResponse {
        $result = $mfa->verifyChallenge(
            $request,
            $request->string('code')->toString(),
        );

        return $this->completeMfaLogin($request, $result, $mfa, $sessions);
    }

    public function verifyRecoveryCode(
        VerifyMfaRecoveryCodeRequest $request,
        MfaService $mfa,
        AuthenticatedSessionManager $sessions,
    ): JsonResponse {
        $result = $mfa->verifyRecoveryCode(
            $request,
            $request->string('code')->toString(),
        );

        return $this->completeMfaLogin($request, $result, $mfa, $sessions);
    }

    /**
     * @param  array<string, mixed>  $additional
     */
    private function completeMfaLogin(
        Request $request,
        LoginAttemptResult $result,
        MfaService $mfa,
        AuthenticatedSessionManager $sessions,
        array $additional = [],
    ): JsonResponse {
        if ($result->outcome === LoginOutcome::InvalidCredentials) {
            return response()->json([
                'message' => 'The MFA verification code is invalid.',
                'status' => false,
            ], 422)->header('Cache-Control', 'no-store, private')
                ->header('Pragma', 'no-cache');
        }

        if ($result->outcome === LoginOutcome::Locked) {
            $mfa->clearChallenge($request);

            return response()->json([
                'message' => 'Too many invalid MFA codes. Please try again later.',
                'status' => false,
            ], 429)->header('Retry-After', (string) $result->retryAfter)
                ->header('Cache-Control', 'no-store, private')
                ->header('Pragma', 'no-cache');
        }

        if (! $result->user instanceof User) {
            throw new LogicException('A completed MFA login result must contain a user.');
        }

        $sessions->establish($request, $result->user, mfaVerified: true);

        return UserResource::make($result->user)
            ->additional($additional)
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
