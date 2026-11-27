<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\MfaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaVerified
{
    public function __construct(private readonly MfaService $mfa) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $this->mfa->requiresMfa($user)
            && (! $request->hasSession()
                || ! is_numeric($request->session()->get('auth_mfa_verified_at')))) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Multi-factor authentication is required. Please sign in again.',
            ], 401);
        }

        return $next($request);
    }
}
