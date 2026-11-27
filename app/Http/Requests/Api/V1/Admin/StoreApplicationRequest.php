<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'intake_id' => ['required', 'integer', 'exists:intakes,id'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'guardian_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }
}
