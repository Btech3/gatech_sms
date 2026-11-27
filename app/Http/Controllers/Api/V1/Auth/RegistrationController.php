<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function store(RegisterRequest $request): JsonResponse
    {
        $attributes = $request->safe()->only([
            'first_name',
            'last_name',
            'email',
            'password',
        ]);
        $attributes['name'] = trim($attributes['first_name'].' '.$attributes['last_name']);

        [$user, $wasCreated] = DB::transaction(function () use ($attributes): array {
            $user = User::query()->createOrFirst(
                ['email' => $attributes['email']],
                [
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'name' => $attributes['name'],
                    'password' => $attributes['password'],
                ],
            );

            if ($user->wasRecentlyCreated) {
                // New accounts stay unprivileged; only local/testing auth may bypass this activation gate.
                $user->forceFill([
                    'is_active' => false,
                    'email_verified_at' => null,
                ])->save();

                event(new Registered($user));
            }

            return [$user, $user->wasRecentlyCreated];
        });

        if (! $wasCreated) {
            throw ValidationException::withMessages([
                'email' => ['The email address has already been taken.'],
            ]);
        }

        return UserResource::make($user)
            ->additional([
                'message' => 'Registration created. Verification email queued; account remains inactive until verified.',
            ])
            ->response()
            ->setStatusCode(201);
    }
}
