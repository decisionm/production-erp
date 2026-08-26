<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Production\Models\ProductionRequest;
use RuntimeException;

/**
 * A production request was asked to do something its state or the order
 * behind it does not allow. Always an expected business-rule refusal (a 422
 * naming what is actually true), never a bug.
 *
 * Modelled on Inventory's MaterialRequestLifecycleException — the same
 * document-shaped refusal for the other direction of the yard.
 */
class ProductionRequestException extends RuntimeException implements DomainException
{
    /**
     * THE ONE-OPEN-REQUEST RULE. MySQL cannot express "unique where status
     * in (queued, in_progress)" as a partial index, so the rule lives in the
     * service under a lock and refuses here. A produced or cancelled request
     * does NOT block a new one — a line whose first run was scrapped must be
     * able to ask again.
     */
    public static function alreadyOpenForLine(ProductionRequest $existing): self
    {
        return new self(
            "This order line already has an open production request ({$existing->documentNumber()}, "
            ."{$existing->status->value}). Cancel or finish that one before raising another."
        );
    }

    /** The order has to be live before the floor is asked to make anything for it. */
    public static function orderNotOpen(string $documentNumber, string $status): self
    {
        return new self(
            "Sales order {$documentNumber} is {$status}: production can only be asked for a confirmed or "
            .'partially delivered order.'
        );
    }

    /**
     * S14, THE SHORTFALL CAP. What the floor is asked for is capped at what
     * the line is genuinely short of — ordered less delivered less held.
     * Asking for more would put pieces on the worklist that no customer is
     * waiting for, ahead of pieces somebody is.
     */
    public static function nothingShort(string $lineLabel): self
    {
        return new self(
            "Order line {$lineLabel} is fully covered by stock already held and delivered — there is nothing "
            .'for the floor to make.'
        );
    }

    /** A quantity has to be a quantity. */
    public static function quantityNotPositive(string $requested): self
    {
        return new self("A production request has to be for more than nothing — {$requested} was asked for.");
    }

    /** Only a queued request can be picked up; one already in progress or finished cannot. */
    public static function cannotStart(ProductionRequest $request): self
    {
        return new self(
            "{$request->documentNumber()} is {$request->status->value}: only a queued request can be started."
        );
    }

    /**
     * A finished request cannot be withdrawn. Cancelling is paperwork and
     * reverses nothing on the floor — pieces already made stay made, and
     * they reach the order through a hold, not by un-cancelling this row.
     */
    public static function cannotCancel(ProductionRequest $request): self
    {
        return new self(
            "{$request->documentNumber()} is {$request->status->value}: it can no longer be cancelled."
        );
    }

    /**
     * reorder() rewrites the WHOLE open queue in one transaction. A partial
     * list would leave the requests it omitted holding stale priorities
     * against the ones it renumbered — two rows claiming the same place.
     *
     * @param  list<int>  $missing
     */
    public static function reorderMustCoverQueue(array $missing, int $given, int $open): self
    {
        $names = $missing === [] ? '' : ' Missing: '.implode(', ', array_map(fn (int $id) => "PR-{$id}", $missing)).'.';

        return new self(
            "The queue has {$open} open requests and {$given} were listed — a reorder has to name every one of "
            ."them, because it renumbers the whole queue.{$names} Reload the queue and try again."
        );
    }

    public function errorCode(): string
    {
        return 'production_request';
    }
}
