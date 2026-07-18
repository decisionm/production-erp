<?php

namespace App\Modules\Procurement\Models\Enums;

enum PurchaseRequisitionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
