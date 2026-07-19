<?php

namespace App\Modules\Quality\Models\Enums;

enum MeasuringInstrumentStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
