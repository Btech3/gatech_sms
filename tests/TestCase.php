<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(UserContract $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        $requiredRoles = config('auth.mfa.required_roles', []);

        if ($user instanceof User
            && is_array($requiredRoles)
            && $requiredRoles !== []
            && $user->roles()->whereIn('roles.name', $requiredRoles)->exists()) {
            config()->set('sanctum.stateful', array_values(array_unique(array_merge(
                config('sanctum.stateful', []),
                ['localhost:5173'],
            ))));
            $this->withHeaders(['Origin' => 'http://localhost:5173']);
            $this->withSession(['auth_mfa_verified_at' => now()->timestamp]);
        }

        return $this;
    }
}
