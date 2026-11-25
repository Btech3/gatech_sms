<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Notifications\Auth\EmailChangeVerification;
use App\Services\Auth\InvalidateUserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProfileController extends Controller
{
    public function __construct(private readonly InvalidateUserSessions $sessions) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $attributes = $request->safe()->only(['first_name', 'last_name', 'phone']);
        $newEmail = $request->validated('email');
        $emailChangeRequested = is_string($newEmail) && $newEmail !== $user->email;
        $oldPhotoPath = $user->profile_photo_path;
        $newPhotoPath = null;

        if ($emailChangeRequested && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your current email address before requesting an email change.',
            ], 403);
        }

        if (array_key_exists('first_name', $attributes) || array_key_exists('last_name', $attributes)) {
            $attributes['name'] = trim(
                ($attributes['first_name'] ?? $user->first_name).' '.($attributes['last_name'] ?? $user->last_name),
            );
        }

        if ($emailChangeRequested) {
            $attributes['pending_email'] = $newEmail;
        }

        if ($request->hasFile('profile_photo')) {
            $newPhotoPath = $request->file('profile_photo')->store(
                'profile-photos/'.$user->getKey(),
                'local',
            );

            if (! is_string($newPhotoPath)) {
                throw new RuntimeException('The profile photo could not be stored.');
            }

            $attributes['profile_photo_path'] = $newPhotoPath;
        }

        DB::transaction(function () use ($user, $attributes): void {
            $user->forceFill($attributes)->save();
        });

        if ($newPhotoPath !== null && $oldPhotoPath !== null) {
            if (! Storage::disk('local')->delete($oldPhotoPath)) {
                throw new RuntimeException('The previous profile photo could not be removed.');
            }
        }

        if ($emailChangeRequested) {
            $url = URL::temporarySignedRoute(
                'api.v1.auth.email-change.verify',
                now()->addMinutes(30),
                ['id' => $user->getKey(), 'hash' => sha1($newEmail)],
            );

            Notification::route('mail', $newEmail)
                ->notify(new EmailChangeVerification($url));
        }

        return response()->json([
            'message' => $emailChangeRequested
                ? 'Profile updated. Verify the new email address to apply the change.'
                : 'Profile updated.',
            'data' => (new UserResource($user->fresh()))->resolve($request),
        ]);
    }

    public function showPhoto(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_photo_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        DB::transaction(function () use ($user, $request, $currentSessionId): void {
            $user->forceFill([
                'password' => Hash::make($request->validated('password')),
                'remember_token' => Str::random(60),
            ])->save();

            $this->sessions->forUser($user, $currentSessionId);
        });

        if ($request->hasSession()) {
            $request->session()->regenerate(true);
        }

        return response()->json([
            'message' => 'Your password has been changed. Other active sessions have been signed out.',
        ]);
    }
}
