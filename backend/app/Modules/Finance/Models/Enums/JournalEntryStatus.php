<?php

namespace App\Modules\Finance\Models\Enums;

enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
