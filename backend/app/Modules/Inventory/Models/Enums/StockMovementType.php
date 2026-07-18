<?php

namespace App\Modules\Inventory\Models\Enums;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
}
