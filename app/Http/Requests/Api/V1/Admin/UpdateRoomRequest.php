<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $campus = $this->route('campus');
        $room = $this->route('room');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('rooms', 'name')->where('campus_id', $campus->id)->ignore($room),
            ],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:5000'],
            'type' => ['sometimes', 'required', 'string', Rule::in(Room::TYPES)],
            'status' => ['sometimes', 'required', 'string', Rule::in([Room::STATUS_ACTIVE, Room::STATUS_INACTIVE])],
            'campus_id' => ['prohibited'],
            'room_id' => ['prohibited'],
        ];
    }
}
