<?php

namespace App\Enums\Auth;

enum LoginOutcome
{
    case Authenticated;
    case InvalidCredentials;
    case Locked;
}
