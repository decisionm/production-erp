<?php

namespace App\Modules\Quality\Http\Resources;

use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE ARRIVAL LINE STILL WAITING FOR ITS INCOMING INSPECTION — and NOTHING
 * a quality login is not entitled to see.
 *
 * WHY THIS IS A NEW RESOURCE RATHER THAN THE OBVIOUS TWO. The queue could
 * have been served by `GoodsReceiptNoteLineResource` + `ItemResource`, and
 * both would have been wrong here:
 *
 *  - `GoodsReceiptNoteLineResource` emits `unit_cost` behind a `finance.*`
 *    check and nests `MaterialLotResource` (which carries the receipt rate
 *    behind its own check). A quality reader would then be exactly ONE
 *    permission-grant away from a purchase rate — and FC-06 is not a
 *    permission check that happens to be false today, it is a boundary.
 *    Nothing that can print a rate is reachable from this endpoint at all.
 *  - `ItemResource` carries ~40 columns plus a `can` block that costs the
 *    ItemService an abilities() resolution per row. On a queue that is
 *    deliberately unpaginated that is both a wider surface and a cost.
 *
 * So the shape below is a WHITELIST, written out by hand, and the test
 * `pinsTheExactPayloadShape` asserts the exact key set — not that some
 * particular forbidden key is absent, which passes vacuously the moment a
 * field is renamed or nested one level deeper.
 *
 * Exactly what the scope allows and no more: the GRN reference, the item's
 * identity and name, the exact received quantity, and its unit. No vendor,
 * no rate, no invoice, no accounting, no delivery-challan reference.
 *
 * `received_quantity` is the `decimal:4` cast's own string ("123450.0000"),
 * passed through untouched — the figure the inspection is measured against
 * has to survive the round trip character for character.
 */
class PendingIncomingInspectionLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GoodsReceiptNoteLine $line */
        $line = $this->resource;

        return [
            // The id the inspection is posted against, and the row key.
            'id' => $line->id,
            // The receipt as every document and trace in this app names it
            // ("GRN-{id}"), not the vendor's delivery-challan `reference`.
            'grn_reference' => $line->goodsReceiptNote?->documentNumber(),
            'item' => [
                'id' => $line->item?->id,
                'sku' => $line->item?->sku,
                'name' => $line->item?->name,
            ],
            'received_quantity' => $line->quantity,
            'uom' => $line->item?->uom,
        ];
    }
}
