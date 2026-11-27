<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveAcademicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_session_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_sessions,id'],
            'campus_id' => ['sometimes', 'nullable', 'integer', 'exists:campuses,id'],
            'name' => ['required', 'string', 'max:120'],
            'holiday_date' => ['required', 'date'],
        ];
    }
}
