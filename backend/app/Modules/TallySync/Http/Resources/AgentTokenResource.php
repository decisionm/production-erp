<?php

namespace App\Modules\TallySync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never carries the plain-text token — Sanctum only exposes that once, at
 * the moment of creation (see TallySyncAgentTokenController::store()),
 * and doesn't store it anywhere retrievable afterward. This resource is
 * only ever the metadata: what the token can do and when it was last used.
 */
class AgentTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
