<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class EmailChangeVerificationController extends Controller
{
    public function __invoke(string $id, string $hash): JsonResponse
    {
        $verified = DB::transaction(function () use ($id, $hash): bool {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            $pendingEmail = $user->pending_email;

            if ($pendingEmail === null || ! hash_equals(sha1($pendingEmail), $hash)) {
                return false;
            }

            $user->forceFill([
                'email' => $pendingEmail,
                'pending_email' => null,
                'email_verified_at' => now(),
            ])->save();

            return true;
        });

        if (! $verified) {
            return response()->json([
                'message' => 'The email change link is invalid or has already been used.',
            ], 403);
        }

        return response()->json([
            'message' => 'Your new email address has been verified.',
        ]);
    }
}
