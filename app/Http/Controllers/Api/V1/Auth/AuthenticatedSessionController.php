<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use App\Services\Auth\AuthenticatedSessionManager;
use App\Services\Auth\AuthenticateUser;
use App\Services\Auth\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use LogicException;

class AuthenticatedSessionController extends Controller
{
    public function store(
        LoginRequest $request,
        AuthenticateUser $authenticateUser,
        AuthenticatedSessionManager $sessions,
        MfaService $mfa,
    ): JsonResponse {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A first-party session is required.',
            ], 401);
        }

        $result = $authenticateUser->attempt(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        if ($result->outcome === LoginOutcome::InvalidCredentials) {
            return response()->json([
                'message' => 'The provided credentials are invalid.',
                'status' => false,
            ], 422);
        }

        if ($result->outcome === LoginOutcome::Locked) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'status' => false,
            ], 429)->header('Retry-After', (string) $result->retryAfter);
        }

        if (! $result->user instanceof User) {
            throw new LogicException('An authenticated login result must contain a user.');
        }

        if ($mfa->requiresMfa($result->user)) {
            $retryAfter = $mfa->beginChallenge($request, $result->user);

            if ($retryAfter !== null) {
                return response()->json([
                    'message' => 'Too many invalid MFA codes. Please try again later.',
                    'status' => false,
                ], 429)->header('Retry-After', (string) $retryAfter)
                    ->header('Cache-Control', 'no-store, private')
                    ->header('Pragma', 'no-cache');
            }

            return response()->json([
                'message' => 'Multi-factor authentication is required.',
                'data' => [
                    'enrollment_required' => ! $result->user->hasMfaEnabled(),
                ],
            ], 202)->header('Cache-Control', 'no-store, private')
                ->header('Pragma', 'no-cache');
        }

        $sessions->establish($request, $result->user);

        return (new UserResource($result->user))->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
