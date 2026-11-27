<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\Auth\UserPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'password' => UserPasswordPolicy::rules(),
            'password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }
}
