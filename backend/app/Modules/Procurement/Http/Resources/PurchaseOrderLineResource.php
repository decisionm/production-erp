<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
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
