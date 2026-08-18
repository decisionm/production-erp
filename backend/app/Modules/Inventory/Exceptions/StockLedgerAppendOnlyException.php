<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * stock_movements is an append-only ledger. A movement, once recorded, is a
 * fact about what happened; a wrong one is answered by a NEW movement that
 * reverses it (the amendment and QC-return paths do exactly this), never by
 * editing or deleting the original — because stock_balances is derived from
 * the ledger, and every report, voucher and trace that already read the row
 * would otherwise be describing something that no longer exists.
 *
 * Thrown by the model's own guard (StockMovement::booted) on any attempt to
 * update or delete a persisted movement through Eloquent. Reaching this is a
 * bug in the caller, not a user error; it still implements DomainException
 * so that if it ever surfaces through the API it reads as a plain 422 with
 * the reason, not a 500.
 */
class StockLedgerAppendOnlyException extends RuntimeException implements DomainException
{
    public static function forUpdate(int|string|null $id): self
    {
        return new self(sprintf(
            'The stock ledger is append-only: stock movement #%s cannot be updated. Record a new movement that reverses it instead.',
            $id ?? '?',
        ));
    }

    public static function forDelete(int|string|null $id): self
    {
        return new self(sprintf(
            'The stock ledger is append-only: stock movement #%s cannot be deleted. Record a new movement that reverses it instead.',
            $id ?? '?',
        ));
    }
}
