<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:200'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('permissions', 'name'),
            ],
            'name' => ['prohibited'],
            'role_id' => ['prohibited'],
            'is_system' => ['prohibited'],
            'is_bootstrap' => ['prohibited'],
            'user_ids' => ['prohibited'],
        ];
    }
}
