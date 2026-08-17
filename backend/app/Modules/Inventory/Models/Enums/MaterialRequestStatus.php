<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * The life of a production material request.
 *
 *   draft            raised on the floor, not yet in the store's queue
 *   submitted        in the STORE'S QUEUE, nothing handed over yet
 *   partially_issued some of it is standing in Production/WIP; a remainder
 *                    is still owed
 *   issued           nothing is owed — every line has been handed over
 *   cancelled        withdrawn, with a reason and an author
 *
 * NONE OF THESE IS A CONSUMPTION. `issued` means the material has moved
 * from the Raw Material Store into Production/WIP (DEC-20260817-001) and is
 * standing there in somebody's hands; what a batch actually consumed is a
 * separate, later, calculated event. A screen that prints "issued" as
 * "used" is printing a different fact.
 *
 * Deliberately NOT the same enum as Procurement's
 * PurchaseRequisitionStatus (draft/approved/rejected): that document is a
 * request to BUY from a vendor and is approved or refused; this one is a
 * request to be HANDED material the factory already owns, and it is
 * fulfilled in parts.
 */
enum MaterialRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PartiallyIssued = 'partially_issued';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    /** In the store's queue, waiting to be fulfilled in whole or in part. */
    public function isOpenToTheStore(): bool
    {
        return $this === self::Submitted || $this === self::PartiallyIssued;
    }

    /** Finished for good — nothing further happens to this request. */
    public function isFinal(): bool
    {
        return $this === self::Issued || $this === self::Cancelled;
    }
}
