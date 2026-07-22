<?php

namespace App\Modules\Production\Models\Enums;

enum ShiftScrapType: string
{
    case RejectedFinishedGood = 'rejected_finished_good';
    case Lumps = 'lumps';
}
