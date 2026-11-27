<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'date_format' => [
                'sometimes',
                'required',
                Rule::in(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y']),
            ],
            'letterhead' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
