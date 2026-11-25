<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Models\User;
use App\Notifications\Auth\EmailChangeVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

class ResendVerificationController extends Controller
{
    public function store(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->validated('email'))
            ->orWhere('pending_email', $request->validated('email'))
            ->first();

        if ($user?->pending_email === $request->validated('email')) {
            $url = URL::temporarySignedRoute(
                'api.v1.auth.email-change.verify',
                now()->addMinutes(30),
                ['id' => $user->getKey(), 'hash' => sha1($user->pending_email)],
            );

            Notification::route('mail', $user->pending_email)
                ->notify(new EmailChangeVerification($url));
        } elseif ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If the address belongs to an unverified account, a verification link will be sent.',
        ], 202);
    }
}
