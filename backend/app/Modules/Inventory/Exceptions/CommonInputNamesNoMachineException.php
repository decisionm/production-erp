<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\Item;
use RuntimeException;

/**
 * FC-01, at the request desk.
 *
 * The factory has ONE resin loading point — crane-fed, piped to all ten
 * machines (DEC-20260807-006). A request that asks the store for resin
 * "for machine 3" describes a factory that does not exist, and the moment
 * the store fulfilled it the ledger would carry a bag-to-machine claim
 * that FC-01 forbids.
 *
 * It is REFUSED rather than silently corrected. Quietly dropping the
 * machine would file the request under a different meaning than the person
 * typed, and they would never know — the same reason `loadBag` has no
 * machine parameter at all rather than an ignored one.
 */
class CommonInputNamesNoMachineException extends RuntimeException implements DomainException
{
    public static function forItem(Item $item): self
    {
        return new self(
            "A common-input request names no machine and no area. \"{$item->name}\" ({$item->sku}) is drawn from the "
            .'factory\'s single resin loading point — crane-fed and piped to every machine (DEC-20260807-006) — so a '
            .'bag belongs to no machine and no batch (FC-01). Remove the work centre, or raise the machine-specific '
            .'consumables (film, cartons, tape) as their own request.'
        );
    }

    public function errorCode(): string
    {
        return 'common_input_names_no_machine';
    }
}
