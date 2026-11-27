<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class SaveIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'academic_session_id' => [$required, 'integer', 'exists:academic_sessions,id'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'campus_id' => ['sometimes', 'nullable', 'integer', 'exists:campuses,id'],
            'name' => [$required, 'string', 'max:120'],
            'starts_on' => [$required, 'date'],
            'applications_open_at' => [$required, 'date'],
            'applications_close_at' => [$required, 'date'],
            'capacity' => [$required, 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $intake = $this->route('intake');
                $opensAt = $this->input('applications_open_at', $intake?->applications_open_at?->toDateTimeString());
                $closesAt = $this->input('applications_close_at', $intake?->applications_close_at?->toDateTimeString());

                if ($opensAt !== null && $closesAt !== null && Carbon::parse($closesAt)->lessThanOrEqualTo($opensAt)) {
                    $validator->errors()->add(
                        'applications_close_at',
                        'The application closing time must be after the opening time.',
                    );
                }
            },
        ];
    }
}
