<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_session_id' => $this->academic_session_id,
            'campus_id' => $this->campus_id,
            'name' => $this->name,
            'holiday_date' => $this->holiday_date?->toDateString(),
        ];
    }
}
