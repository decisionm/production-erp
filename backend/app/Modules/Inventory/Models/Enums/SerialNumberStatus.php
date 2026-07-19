<?php

namespace App\Modules\Inventory\Models\Enums;

enum SerialNumberStatus: string
{
    // Known/registered but never yet received into a warehouse — distinct
    // from InStock, which always implies a warehouse_id is set.
    case Registered = 'registered';
    case InStock = 'in_stock';
    case Consumed = 'consumed';
    case Sold = 'sold';
    case Scrapped = 'scrapped';
}
