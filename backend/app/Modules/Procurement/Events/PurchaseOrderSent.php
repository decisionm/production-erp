<?php

namespace App\Modules\Procurement\Events;

use App\Modules\Procurement\Models\PurchaseOrder;

/**
 * Raised when an ERP-raised purchase order goes Draft → Sent (Phase 6,
 * P6-03) — AFTER the transaction that sent it committed. Procurement
 * announces the fact; it does not know or care that TallySync listens
 * (which, with tally-sync.purchase_orders_enabled ON, stages a Purchase
 * Order voucher, and with it OFF — the default, owner-gated per Q35 —
 * records `tally_staging: disabled` on the order and enqueues nothing).
 * The same shape as GoodsReceiptNoteReceived; decouples the modules per
 * CLAUDE.md. Never raised for a Tally-originated mirror (a mirror is born
 * Sent and cannot be sent).
 */
class PurchaseOrderSent
{
    public function __construct(public readonly PurchaseOrder $order) {}
}
