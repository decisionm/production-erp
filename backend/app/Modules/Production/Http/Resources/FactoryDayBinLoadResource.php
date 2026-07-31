<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One load INTO the factory day bin (a transfer_in stock movement into the
 * bin warehouse): when, what, how much, who — and the bag barcode when the
 * load came from a bag scan (parsed back out of the fixed reference
 * FactoryDayBinService::loadBag stamps; manual transfers carry whatever
 * free-text reference the form gave, possibly none).
 */
class FactoryDayBinLoadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'time' => $this->movement_date?->toIso8601String(),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity_kg' => $this->quantity,
            'bag_barcode' => $this->bagBarcode(),
            // The audit identity — the authenticated user who performed the
            // load. (An acting supervisor, when noted, is free text in
            // `notes`, never an identity.)
            'user' => $this->createdBy?->name,
            'reference' => $this->reference,
        ];
    }

    private function bagBarcode(): ?string
    {
        $reference = (string) $this->reference;

        return Str::startsWith($reference, FactoryDayBinService::BAG_LOAD_REFERENCE_PREFIX)
            ? Str::after($reference, FactoryDayBinService::BAG_LOAD_REFERENCE_PREFIX)
            : null;
    }
}
