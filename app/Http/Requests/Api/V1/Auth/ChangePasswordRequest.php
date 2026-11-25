<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\Auth\UserPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => UserPasswordPolicy::rules(),
            'password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }
}
