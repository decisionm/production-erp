<?php

namespace App\Modules\CRM\Models\Enums;

enum LeadActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Note = 'note';
}
