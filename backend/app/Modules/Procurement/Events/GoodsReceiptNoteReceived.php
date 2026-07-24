<?php

namespace App\Modules\Procurement\Events;

use App\Modules\Procurement\Models\GoodsReceiptNote;

/**
 * Raised when raw material is received against a purchase order. Procurement
 * announces the fact; it does not know or care that TallySync listens (which
 * enqueues a Tally Receipt Note voucher). Decouples the modules per CLAUDE.md.
 */
class GoodsReceiptNoteReceived
{
    public function __construct(public readonly GoodsReceiptNote $note) {}
}
