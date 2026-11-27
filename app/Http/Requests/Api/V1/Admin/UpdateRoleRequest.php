<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
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
                if (! $this->exists('name')
                    || $validator->errors()->has('name')
                    || ! is_string($this->input('name'))) {
                    if (! $this->exists('name') && ! $this->exists('description')) {
                        $validator->errors()->add('name', 'Provide at least one role field to update.');
                    }

                    return;
                }

                $query = Role::query()
                    ->where('name_key', Str::lower($this->input('name')));
                $currentRole = $this->route('role');

                if ($currentRole instanceof Role) {
                    $query->where('id', '!=', $currentRole->id);
                }

                if ($query->exists()) {
                    $validator->errors()->add('name', 'The name has already been taken.');
                }
            },
        ];
    }
}
