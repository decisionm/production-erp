<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored packing line — the wire line the completion was validated
 * against, read back under the same names it was sent with.
 */
class ShiftProductionEntryPackingLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'mode' => $this->mode,
            'production_standard_packaging_id' => $this->production_standard_packaging_id,
            'boxes' => $this->boxes,
            'nos_per_box' => $this->nos_per_box,
            'loose_inner' => $this->loose_inner,
            'nos_per_inner' => $this->nos_per_inner,
            'derived_pieces' => $this->derived_pieces,
            'actual_pieces' => $this->actual_pieces,
            'override_reason' => $this->override_reason,
        ];
    }
}
