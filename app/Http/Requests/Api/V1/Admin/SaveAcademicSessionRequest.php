<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AcademicSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAcademicSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $session = $this->route('academic_session');
        $sessionId = $session instanceof AcademicSession ? $session->id : null;

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('academic_sessions', 'name')->ignore($sessionId)],
            'starts_on' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'date'],
            'ends_on' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'date'],
            'is_current' => ['sometimes', 'boolean'],
            'is_closed' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $session = $this->route('academic_session');
                $sessionId = $session instanceof AcademicSession ? $session->id : null;
                $startsOn = $this->input('starts_on', $session?->starts_on?->toDateString());
                $endsOn = $this->input('ends_on', $session?->ends_on?->toDateString());

                if ($startsOn === null || $endsOn === null) {
                    return;
                }

                if ($startsOn > $endsOn) {
                    $validator->errors()->add('ends_on', 'The end date must be on or after the start date.');

                    return;
                }

                $isClosed = $this->boolean('is_closed', $session?->is_closed ?? false);
                $isCurrent = $this->boolean('is_current', $session?->is_current ?? false);

                if ($isClosed && $isCurrent) {
                    $validator->errors()->add('is_current', 'A closed academic session cannot be current.');

                    return;
                }

                $overlaps = AcademicSession::query()
                    ->when($sessionId !== null, fn ($query) => $query->whereKeyNot($sessionId))
                    ->whereDate('starts_on', '<=', $endsOn)
                    ->whereDate('ends_on', '>=', $startsOn)
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('starts_on', 'The academic session dates cannot overlap another session.');
                }
            },
        ];
    }
}
