<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessGrantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
            ]),
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->public_id,
                'name' => $this->application->name,
                'slug' => $this->application->slug,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->public_id,
                'hcode' => $this->organization?->hcode,
                'name_th' => $this->organization?->name_th,
            ]),
            'role' => $this->role,
            'permissions' => $this->permissions ?? [],
            'is_active' => $this->is_active,
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
