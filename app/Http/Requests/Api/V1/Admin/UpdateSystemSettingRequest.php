<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Settings\SystemSettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(SystemSettingsCatalog $catalog): array
    {
        $key = (string) $this->route('key');

        return [
            'value' => $catalog->valueRules($key),
            'value.*' => match ($key) {
                'payment.currencies' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
                'payment.methods', 'notification.channels', 'document.required_documents' => ['required', 'string', 'max:100'],
                'document.allowed_file_types' => ['required', Rule::in(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'])],
                default => [],
            },
        ];
    }
}
