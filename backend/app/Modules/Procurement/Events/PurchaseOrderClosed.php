<?php

namespace App\Modules\Procurement\Events;

use App\Modules\Procurement\Models\PurchaseOrder;

/**
 * Raised when an ERP-raised purchase order is SHORT-CLOSED (Sent |
 * PartiallyReceived → Closed) — AFTER the transaction that closed it
 * committed. The same shape as PurchaseOrderSent: Procurement announces the
 * fact and knows nothing about who listens.
 *
 * TallySync listens (TallySyncEventServiceProvider): a Purchase Order
 * voucher this ERP staged but the agent has NOT collected yet is withdrawn
 * (Dismissed) and the withdrawal recorded on the order; one the agent
 * already holds is left exactly as it is. What Tally should be told about a
 * closed order it already received is owner question Q48 — not built.
 */
class PurchaseOrderClosed
{
    public function __construct(public readonly PurchaseOrder $order) {}
}
