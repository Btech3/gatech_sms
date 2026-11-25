<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use App\Services\Auth\AuthenticateUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use LogicException;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request, AuthenticateUser $authenticateUser): JsonResponse
    {
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

        Auth::guard('web')->login($result->user);
        $request->session()->regenerate();
        $request->session()->put('auth_last_activity_at', now()->timestamp);

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
