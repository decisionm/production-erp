<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            // Set only for godowns pulled from Tally — the frontend uses this
            // to default entries to a godown Tally will actually accept.
            'tally_guid' => $this->tally_guid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
