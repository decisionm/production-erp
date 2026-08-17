<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderLineResource extends JsonResource
{
    /**
     * Whether THIS reader is served the purchase rate (`unit_price`). The
     * purchase rate is Owner/Accounts data (FC-06): same gate, same
     * omit-not-null rule as MaterialLotResource — see its class note. The
     * ONE predicate: toArray() reads it for the screen, and the Export
     * Center's PurchaseOrderLinesExport reads it for the file's columns, so
     * the two can never disagree about who sees a rate.
     */
    public static function showsCost(?Authenticatable $reader): bool
    {
        return $reader?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;
    }

    public function toArray(Request $request): array
    {
        // Served open, this line handed the rate to any procurement viewer
        // while the lot it became was correctly hiding it.
        $showsCost = self::showsCost($request->user());

        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            ...($showsCost ? ['unit_price' => $this->unit_price] : []),
            'quantity_received' => $this->quantity_received,
            // Item/due-date delivery windows, oldest due first. remaining is
            // served so the arrival preview can show what each window still
            // expects without re-deriving it client-side.
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'due_date' => $schedule->due_date->toDateString(),
                'quantity' => $schedule->quantity,
                'quantity_received' => $schedule->quantity_received,
                'remaining' => $schedule->remaining(),
                'tally_reference' => $schedule->tally_reference,
            ])->all()),
        ];
    }
}
