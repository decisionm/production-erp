<?php

namespace App\Modules\Finance\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class UnbalancedJournalEntryException extends RuntimeException implements DomainException
{
    public static function make(string $totalDebit, string $totalCredit): self
    {
        return new self(
            "Journal entry does not balance: total debit {$totalDebit} does not equal total credit {$totalCredit}."
        );
    }
}
