<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['accept', 'reject', 'waitlist'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
            'waitlist_expires_at' => ['required_if:decision,waitlist', 'nullable', 'date', 'after:now'],
        ];
    }
}
