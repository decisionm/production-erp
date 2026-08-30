<?php

namespace App\Modules\Procurement\Models\Enums;

enum SupplierBillStatus: string
{
    /** Being typed from the paper — editable. */
    case Draft = 'draft';
    /** Entry confirmed done (who/when stamped) — read-only from here. */
    case Recorded = 'recorded';
    /** Withdrawn with a reason — the row stays, nothing is deleted. */
    case Cancelled = 'cancelled';
}
