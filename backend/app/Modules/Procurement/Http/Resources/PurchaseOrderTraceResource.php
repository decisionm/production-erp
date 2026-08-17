<?php

namespace App\Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /procurement/purchase-orders/{po}/trace (Phase 6, P6-02): the chain
 * PurchaseOrderTraceService::orderTrace() built for THIS reader —
 * {purchase_order, lines, receipts[…lines[…stock_movements, material_lots
 * […bags[…loads]]]], consumption} — inside `data`, as every resource is.
 * The service already shaped and FC-06-gated every key (a rate rides only
 * for a finance reader; `rate_withheld` stands in for everyone else), so
 * this resource adds nothing and hides nothing.
 */
class PurchaseOrderTraceResource extends JsonResource
{
    /** @param array<string, mixed> $trace */
    public function __construct(array $trace)
    {
        parent::__construct($trace);
    }

    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
