<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
            ])),
            'campuses' => $this->whenLoaded('campuses', fn () => $this->campuses->map(fn ($campus): array => [
                'id' => $campus->id,
                'name' => $campus->name,
                'is_primary' => (bool) $campus->pivot->is_primary,
            ])),
        ];
    }
}
