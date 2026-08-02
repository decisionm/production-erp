<?php

namespace App\Modules\Inventory\Models\Enums;

enum MaterialBagStatus: string
{
    case InStore = 'in_store';
    case InDayBin = 'in_day_bin';
    case Consumed = 'consumed';
    case Returned = 'returned';
    // Born at arrival, before Incoming QC: the bag's permanent identity and
    // label exist, but it is unavailable to production until QC disposition
    // releases it (owner-confirmed arrival flow).
    case WaitingQc = 'waiting_qc';
    // QC rejected the bag whole. Its kilograms leave usable stock via a
    // 'Rejections Out' issue; the identity survives for the audit trail and
    // for the later Rejections Out voucher reference.
    case RejectedQc = 'rejected_qc';
}
