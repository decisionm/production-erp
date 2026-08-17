<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\PurchaseOrderRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a purchase order's append-only history (Phase 6): what a
 * Draft's lines were before an amendment (kind 'amend'), or what was still
 * open per line at a short-close (kind 'close'). Rides only on the show
 * endpoint.
 *
 * FC-06: an amend snapshot carries the purchase rate the line had
 * (unit_price). It is served ONLY to a reader PurchaseOrderLineResource::
 * showsCost() admits — the same predicate the live lines use — and for
 * everyone else the key is ABSENT and `rate_withheld` stands where it
 * would (the trace's convention), never a null that could read as "cost
 * nothing". A close snapshot has no rate (quantities only) and no note.
 */
class PurchaseOrderRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrderRevision $revision */
        $revision = $this->resource;
        $showsCost = PurchaseOrderLineResource::showsCost($request->user());

        return [
            'id' => $revision->id,
            'revision_no' => $revision->revision_no,
            'kind' => $revision->kind,
            'reason' => $revision->reason,
            'amended_by' => $revision->amended_by,
            'created_at' => $revision->created_at?->toIso8601String(),
            'lines' => array_map(
                fn (array $line) => self::gatedLine($line, $showsCost),
                array_values($revision->lines_json ?? []),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function gatedLine(array $line, bool $showsCost): array
    {
        if ($showsCost || ! array_key_exists('unit_price', $line)) {
            return $line;
        }

        unset($line['unit_price']);
        $line['rate_withheld'] = PurchaseOrderLineResource::RATE_WITHHELD;

        return $line;
    }
}
