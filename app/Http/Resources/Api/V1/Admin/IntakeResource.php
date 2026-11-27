<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntakeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_session_id' => $this->academic_session_id,
            'course_id' => $this->course_id,
            'campus_id' => $this->campus_id,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'applications_open_at' => $this->applications_open_at?->toIso8601String(),
            'applications_close_at' => $this->applications_close_at?->toIso8601String(),
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
        ];
    }
}
