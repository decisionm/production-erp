<?php

namespace App\Modules\TallySync\Models\Enums;

enum TallySyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
