<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Services\RequisitionCoverageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisition $requisition */
        $requisition = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $requisition->documentNumber(),
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            // The decision trail (28-Aug audit finding 8). NULL name + NULL
            // instant on a requisition decided before the stamps existed —
            // the page words that honestly rather than inventing an approver.
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->name),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'needed_by_date' => $this->needed_by_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => PurchaseRequisitionLineResource::collection($this->whenLoaded('lines')),
            // The orders raised FROM this requisition — id + status only;
            // the PO list is where an order is read.
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders
                ->map(fn ($order) => [
                    'id' => $order->id,
                    'status' => $order->status->value,
                    // "PO-{id}" — so a row can name the order the way every
                    // other screen names it, without composing the string
                    // itself in three places.
                    'document_number' => $order->documentNumber(),
                    // Whether THIS order is one of those holding quantity
                    // against the requisition. The reader gets the ANSWER
                    // rather than the rule to apply — the rule is a
                    // predicate, not a status (a cancelled order counts only
                    // if it was ever sent), and re-deriving it on the screen
                    // is how the two come to disagree.
                    'reserves_quantity' => RequisitionCoverageService::reserves($order),
                ])
                ->values()
                ->all()),
            // THE REQUISITION IN ONE WORD, for a list cell: Fully Ordered
            // only when every line is, Not Ordered when no line has been
            // touched, Partially Ordered in between — the roll-up of the
            // per-line words above, never a quantity. A requisition's lines
            // may be in Kgs and Nos at once, so it has no total to report
            // (RequisitionCoverageService's class note).
            //
            // Absent, like the per-line figures, when the lines were not
            // decorated: silence, rather than a word that would read as
            // "nothing is ordered".
            'order_status' => $this->when(
                $requisition->relationLoaded('lines')
                    && $requisition->lines->every(fn ($line) => $line->coverage !== null),
                fn () => RequisitionCoverageService::rollUp(
                    $requisition->lines->map(fn ($line) => $line->coverage['order_status'])->all(),
                ),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
