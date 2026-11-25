<?php

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

final class UserPasswordPolicy
{
    /**
     * Return the common password strength and confirmation requirements.
     *
     * @return array<int, string|Password>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            'max:255',
            Password::min(8)->mixedCase()->numbers(),
        ];
    }
}
