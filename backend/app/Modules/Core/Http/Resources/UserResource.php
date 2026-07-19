<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])),
            // Flattened from all assigned roles — this is what the SPA uses
            // to decide which nav items/pages to show. Cheap as long as
            // roles.permissions was eager-loaded by the caller.
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
