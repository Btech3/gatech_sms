<?php

namespace App\Support\Auth;

use App\Enums\Auth\LoginOutcome;
use App\Models\User;

final readonly class LoginAttemptResult
{
    public function __construct(
        public LoginOutcome $outcome,
        public ?User $user = null,
        public int $retryAfter = 0,
    ) {}
}
