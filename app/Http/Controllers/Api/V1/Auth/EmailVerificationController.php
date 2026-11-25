<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends Controller
{
    public function __invoke(string $id, string $hash): JsonResponse
    {
        // The signed link proves email ownership, so verification must work before login is allowed.
        /** @var array{user: User, newly_verified: bool}|null $verification */
        $verification = DB::transaction(function () use ($id, $hash): ?array {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return null;
            }

            $newlyVerified = ! $user->hasVerifiedEmail();

            if ($newlyVerified) {
                $user->markEmailAsVerified();
            }

            $user->forceFill(['is_active' => true])->save();

            return [
                'user' => $user,
                'newly_verified' => $newlyVerified,
            ];
        });

        if ($verification === null) {
            return response()->json([
                'message' => 'The email verification link is invalid.',
            ], 403);
        }

        if ($verification['newly_verified']) {
            event(new Verified($verification['user']));
        }

        return response()->json([
            'message' => 'Email verified. Your account is now active.',
        ]);
    }
}
