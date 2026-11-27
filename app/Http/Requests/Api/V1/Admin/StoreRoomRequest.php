<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $campus = $this->route('campus');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('rooms', 'name')->where('campus_id', $campus->id),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'type' => ['required', 'string', Rule::in(Room::TYPES)],
            'status' => ['required', 'string', Rule::in([Room::STATUS_ACTIVE, Room::STATUS_INACTIVE])],
            'campus_id' => ['prohibited'],
            'room_id' => ['prohibited'],
        ];
    }
}
