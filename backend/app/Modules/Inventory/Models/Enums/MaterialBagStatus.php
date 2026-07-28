<?php

namespace App\Modules\Inventory\Models\Enums;

enum MaterialBagStatus: string
{
    case InStore = 'in_store';
    case InDayBin = 'in_day_bin';
    case Consumed = 'consumed';
    case Returned = 'returned';
}
