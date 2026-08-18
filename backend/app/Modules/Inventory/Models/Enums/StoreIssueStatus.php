<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * Where a store issue stands — and deliberately NOT how much of it has been
 * consumed, which is a thing the store cannot know.
 *
 * Consumption is calculated by the batch (FC-01: a bag belongs to no batch),
 * so an issue is never "consumed": it is standing with production until
 * somebody says otherwise. The two ways it stops standing are a RETURN of
 * material that came back, and a COMPLETE that closes it because production
 * used what it was given.
 *
 *   Issued ──return(part)──► PartiallyReturned ──return(rest)──► Returned
 *      │                            │
 *      ├──complete──► Completed ◄───┘
 *      └──cancel────► Cancelled   (everything reversed, nothing returned yet)
 */
enum StoreIssueStatus: string
{
    /** Handed over. The material is in Production/WIP and unconsumed. */
    case Issued = 'issued';

    /** Some of it came back to the store; the rest is still with production. */
    case PartiallyReturned = 'partially_returned';

    /** All of it came back. Nothing is standing with production. */
    case Returned = 'returned';

    /** Closed by the store: production used what it kept. No stock moves. */
    case Completed = 'completed';

    /** Reversed in full, with a reason. Only possible while untouched. */
    case Cancelled = 'cancelled';

    /** Is this issue still holding material in Production/WIP? */
    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::PartiallyReturned;
    }
}
