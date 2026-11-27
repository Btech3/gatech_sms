<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionManager
{
    public function establish(Request $request, User $user, bool $mfaVerified = false): void
    {
        Auth::guard('web')->login($user);
        $request->session()->forget([
            'auth.mfa.challenge.user_id',
            'auth.mfa.challenge.expires_at',
            'auth.mfa.challenge.enrollment_required',
        ]);
        $request->session()->regenerate();
        $request->session()->put('auth_last_activity_at', now()->timestamp);

        if ($mfaVerified) {
            $request->session()->put('auth_mfa_verified_at', now()->timestamp);

            return;
        }

        $request->session()->forget('auth_mfa_verified_at');
    }
}
