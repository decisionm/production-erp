<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
