<?php

namespace App\Modules\TallySync\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * TallySyncService::enqueueGoodsReceiptNote() REFUSED to stage a Tally
 * 'Receipt Note' voucher — whether the factory uses Tally Receipt Notes at
 * all is PENDING Q63 and unanswered, so tally-sync.receipt_notes_enabled is
 * OFF by default as the fail-closed reading of an open question.
 *
 * The event listener (TallySyncEventServiceProvider) already checks this
 * config itself and never calls enqueueGoodsReceiptNote() while it is off,
 * so this guard is not that listener's path — it is the SECOND lock: the
 * service method refuses on its own, so a future or direct caller (a new
 * controller action, a console command, anything that is not today's one
 * gated listener) cannot create a Receipt Note queue row while the flag is
 * off either. pending() withholding an already-queued row is the THIRD
 * lock, for rows that exist despite this one — see its own docblock.
 */
class ReceiptNoteNotPostable extends RuntimeException implements DomainException
{
    public static function disabled(): self
    {
        return new self(
            'Receipt Note posting to Tally is disabled (tally-sync.receipt_notes_enabled = false — '
            .'the factory does not use Tally Receipt Notes for GRN/inward).',
        );
    }
}
