<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'checklist' => ['sometimes', 'array'],
            'checklist.*' => ['string', 'max:200'],
            'request_information' => ['sometimes', 'boolean'],
        ];
    }
}
