<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Sales\Exceptions\DispatchQualityException;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * INTERNAL QUALITY'S SIGN-OFF ON A SALES ORDER LINE — DEC-20260831-003.
 *
 * The owner's sequence is: stock fully held → QUALITY APPROVES → Sales
 * dispatches → Sales issues the invoice. This service is the second step, and
 * DeliveryService refuses on the cap it writes.
 *
 * THE QUANTITY IS THE POINT. Approving stamps what the line ACTUALLY HELD at
 * the moment Quality looked, and dispatch may not exceed it. Recording only a
 * boolean would let a hold be released after approval and a different lot
 * re-held under a sign-off nobody gave for it — the approval has to be of
 * something, not merely of a row.
 *
 * IT MOVES NO STOCK AND HOLDS NOTHING. Approving is a piece of paper: the
 * store's hold is what keeps the stock, and dispatch is what moves it.
 *
 * NOT A ONE-WAY DOOR, deliberately. Quality may withdraw an approval right up
 * until goods actually go — the repo has paid for one-way doors before. Once
 * anything has been dispatched the approval becomes history and is refused
 * rather than rewritten; a non-conformance report is the road after that.
 */
class DispatchQualityApprovalService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    /**
     * Quality signs the line off for the quantity it currently holds.
     *
     * Judged and written under a row lock in one transaction: a release
     * committing between the coverage read and the stamp would record an
     * approval for stock the line no longer holds, which is precisely the
     * figure the dispatch cap trusts.
     */
    public function approve(SalesOrderLine $line, ?string $note, ?int $userId): SalesOrderLine
    {
        return DB::transaction(function () use ($line, $note, $userId) {
            $locked = SalesOrderLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing('salesOrder');

            $status = $locked->salesOrder?->status;
            if (! in_array($status, [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered], true)) {
                throw DispatchQualityException::orderNotLive($status?->value ?? 'unknown');
            }

            if ($locked->isQualityApproved()) {
                throw DispatchQualityException::alreadyApproved();
            }

            $outstanding = bcsub((string) $locked->quantity, (string) $locked->quantity_delivered, 4);
            $outstanding = bccomp($outstanding, '0', 4) === 1 ? $outstanding : '0.0000';

            $held = $this->reservations->heldOnLineLocked((int) $locked->id);

            // FULLY HELD IS THE OWNER'S PRECONDITION, not a convenience: the
            // sequence reads "stock fully held → Quality approves", so a line
            // still short of stock is not yet Quality's to look at.
            if (bccomp($held, $outstanding, 4) === -1) {
                throw DispatchQualityException::notFullyHeld($held, $outstanding);
            }

            $locked->forceFill([
                'quality_approved_at' => now(),
                'quality_approved_by' => $userId,
                'quality_approved_quantity' => $held,
                'quality_approval_note' => $note,
            ])->save();

            return $locked->fresh();
        });
    }

    /** Withdraw an approval, while and only while nothing has gone out under it. */
    public function revoke(SalesOrderLine $line, ?int $userId): SalesOrderLine
    {
        return DB::transaction(function () use ($line) {
            $locked = SalesOrderLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isQualityApproved()) {
                throw DispatchQualityException::notApproved();
            }

            if (bccomp((string) $locked->quantity_delivered, '0', 4) === 1) {
                throw DispatchQualityException::alreadyDispatched((string) $locked->quantity_delivered);
            }

            $locked->forceFill([
                'quality_approved_at' => null,
                'quality_approved_by' => null,
                'quality_approved_quantity' => null,
                'quality_approval_note' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * What a line may still be dispatched, as the quality gate sees it: the
     * approved quantity less what has already gone. Never negative, and always
     * '0.0000' on a line Quality has not signed.
     */
    public function dispatchableQuantity(SalesOrderLine $line): string
    {
        if (! $line->isQualityApproved()) {
            return '0.0000';
        }

        $remaining = bcsub($line->qualityApprovedQuantity(), (string) $line->quantity_delivered, 4);

        return bccomp($remaining, '0', 4) === 1 ? $remaining : '0.0000';
    }
}
