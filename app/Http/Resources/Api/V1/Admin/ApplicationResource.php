<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'decision_at' => $this->decision_at,
            'decision_reason' => $this->decision_reason,
            'waitlist_position' => $this->waitlist_position,
            'waitlist_expires_at' => $this->waitlist_expires_at,
            'applicant' => $this->whenLoaded('applicant', fn () => [
                'id' => $this->applicant->id,
                'first_name' => $this->applicant->first_name,
                'last_name' => $this->applicant->last_name,
                'email' => $this->applicant->email,
                'phone' => $this->applicant->phone,
            ]),
            'intake_id' => $this->intake_id,
            'campus_id' => $this->campus_id,
            'course_id' => $this->course_id,
        ];
    }
}
