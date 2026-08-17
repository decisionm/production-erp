<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\MaterialLotResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteLineResource extends JsonResource
{
    /**
     * Whether THIS reader is served the receipt rate (`unit_cost`).
     * unit_cost IS the lot's receipt_rate_per_kg (FC-06, Owner/Accounts
     * only): same gate, same omit-not-null rule as the nested
     * MaterialLotResource — see its class note. The ONE predicate:
     * toArray() reads it for the screen, and the Export Center's
     * GoodsReceiptLinesExport reads it for the file's columns, so the two
     * can never disagree about who sees a rate.
     */
    public static function showsCost(?Authenticatable $reader): bool
    {
        return $reader?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;
    }

    public function toArray(Request $request): array
    {
        // Served open, the parent line printed the very number its child
        // lot was hiding.
        $showsCost = self::showsCost($request->user());

        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            // The ledger row this line wrote (Phase 6) — an id, never a
            // rate: null on a line booked before the column existed, and
            // the purchase-order trace says how it resolved such a line.
            'stock_movement_id' => $this->stock_movement_id,
            ...($showsCost ? ['unit_cost' => $this->unit_cost] : []),
            'material_lots' => MaterialLotResource::collection($this->whenLoaded('materialLots')),
        ];
    }
}
