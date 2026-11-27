<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\AcceptInvitationRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function show(User $user, string $token): JsonResponse
    {
        $invitation = $user->invitation()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->first();

        abort_unless($invitation !== null && $invitation->expires_at->isFuture(), 422, 'The invitation is invalid or expired.');

        return response()->json([
            'data' => [
                'first_name' => $user->first_name,
                'email' => $user->email,
                'expires_at' => $invitation->expires_at,
            ],
            'message' => 'Invitation is valid. Submit the token and a new password to the invitation acceptance endpoint.',
        ]);
    }

    public function accept(
        AcceptInvitationRequest $request,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attributes = $request->validated();
        $invitation = DB::transaction(function () use ($attributes, $auditLogger, $request): UserInvitation {
            $invitation = UserInvitation::query()
                ->where('token_hash', hash('sha256', $attributes['token']))
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first();

            abort_unless(
                $invitation !== null && $invitation->expires_at->isFuture(),
                422,
                'The invitation is invalid or expired.',
            );

            $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            abort_if($user->email_verified_at !== null, 409, 'This invitation has already been accepted.');

            $user->forceFill([
                'password' => Hash::make($attributes['password']),
                'remember_token' => Str::random(60),
                'email_verified_at' => now(),
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
            $invitation->update(['accepted_at' => now()]);

            $auditLogger->record(
                $user,
                'Users',
                'invitation_accepted',
                'user',
                $user->id,
                afterValues: ['email_verified' => true, 'is_active' => true],
                request: $request,
            );

            return $invitation->load('user');
        });

        return UserResource::make($invitation->user)
            ->additional(['message' => 'Invitation accepted. You can now sign in.'])
            ->response();
    }
}
