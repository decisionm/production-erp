<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->resource;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            // Archived-by-soft-delete. Nothing archives a warehouse this way
            // today (Archive clears is_active), but a Tally pull can restore
            // a trashed row, so the screen is told rather than guessing.
            'archived_at' => $warehouse->deleted_at?->toIso8601String(),
            // Set only for godowns pulled from Tally — the frontend uses this
            // to default entries to a godown Tally will actually accept.
            'tally_guid' => $this->tally_guid,
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             * WHAT MAY BE DONE TO THIS RECORD, decided by the server
             * (DEC-20260817-002). The SPA reads these and never re-derives
             * eligibility from is_active or from a permission of its own.
             *
             * `delete` carries three answers: true (this user may, and the
             * record is provably unused), false (a decision — either no
             * hard-delete tier, or something references it) and null
             * (undetermined; ask `show`). A list would otherwise pay 8-30
             * COUNT queries PER ROW, so index answers the cheap block and the
             * confirm dialog fetches show() for the authoritative one — which
             * the controller stamps on via withAbilities().
             */
            'can' => $warehouse->can ?? app(WarehouseService::class)
                ->abilities($warehouse, resolveDelete: false, user: $request->user()),
        ];
    }
}
