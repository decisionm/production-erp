<?php

namespace App\Modules\Inventory\Models\Enums;

enum ItemTrackingType: string
{
    case None = 'none';
    case Batch = 'batch';
    case Serial = 'serial';
}
