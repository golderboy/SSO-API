<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
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
            'actor_user_id' => $this->actor?->public_id,
            'action' => $this->action,
            'target_type' => $this->auditable_type,
            'target_id' => $this->target_public_id,
            'context' => $this->context ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
