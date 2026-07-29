<?php

namespace App\Modules\Production\Models\Enums;

/**
 * Draft is the only status an import may create. The factory's master
 * workbook marks every candidate row "To Confirm", and a guessed standard
 * that looks approved is worse than no standard at all — a supervisor
 * cannot tell the difference on the shop floor.
 */
enum ConfigurationStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Inactive = 'inactive';
}
