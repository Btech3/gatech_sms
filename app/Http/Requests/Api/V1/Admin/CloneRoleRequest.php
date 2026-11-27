<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class CloneRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_system' => ['prohibited'],
            'is_bootstrap' => ['prohibited'],
            'permissions' => ['prohibited'],
            'user_ids' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name') || ! is_string($this->input('name'))) {
                    return;
                }

                $exists = Role::query()
                    ->where('name_key', Str::lower($this->input('name')))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'The name has already been taken.');
                }
            },
        ];
    }
}
