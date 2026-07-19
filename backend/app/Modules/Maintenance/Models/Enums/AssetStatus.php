<?php

namespace App\Modules\Maintenance\Models\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case UnderMaintenance = 'under_maintenance';
    case Retired = 'retired';
}
