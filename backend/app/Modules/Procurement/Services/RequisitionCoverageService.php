<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\PurchaseRequisitionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * HOW MUCH OF A REQUISITION HAS ALREADY BEEN ORDERED — the one place that
 * arithmetic exists.
 *
 * A requisition asks for quantities; purchase orders answer it, and there
 * may be MANY orders against one requisition (a buyer splits across
 * vendors, or orders in tranches). Before this, nothing on the requisition
 * said how much of it had been answered: the queue listed the orders raised
 * from it by number and status and left the buyer to add the quantities up
 * by hand, across orders, from another screen.
 *
 * Two readers, one arithmetic:
 *   - PurchaseRequisitionLineResource prints per line — requested, ordered,
 *     balance, and Not Ordered / Partially Ordered / Fully Ordered;
 *   - PurchaseOrderService REFUSES an order whose lines would push the
 *     ordered quantity for an item past what the requisition asked for.
 * They must never disagree, which is why neither owns the sums.
 *
 * PER ITEM, NEVER PER LINE — and NEVER ACROSS ITEMS.
 * The purchase order carries the requisition on its HEADER
 * (purchase_orders.purchase_requisition_id); a purchase-order LINE has no
 * requisition line to point at. So the only honest join is by item: what
 * this requisition asked for of item X, against what every order raised
 * from it has ordered of item X. Both sides GROUP, because neither
 * StorePurchaseRequisitionRequest nor StorePurchaseOrderRequest forbids two
 * lines naming the same item, and a comparison that assumed one line per
 * item would silently under-count a requisition that repeats one.
 *
 * Nothing here ever adds two items together. `items.uom` is Tally's raw
 * free-text base unit — one item's quantities are in Kgs and another's in
 * Nos — so a "total requested" across a requisition would be a number in no
 * unit at all. There is deliberately no such figure in this file, and the
 * screens that read it print item-wise or UoM-wise for the same reason.
 *
 * All arithmetic is bcmath at scale 4, the scale the decimal(15,4) columns
 * and every other quantity comparison in Procurement use.
 */
class RequisitionCoverageService
{
    /** The scale of every quantity column in Procurement — decimal(15,4). */
    private const SCALE = 4;

    /**
     * HOW MUCH THIS ORDER HOLDS against its requisition, per item — the
     * owner's rule (DEC-20260831-006), in one place.
     *
     *   Draft                          holds NOTHING. Nothing is reserved
     *                                  until the order goes to the vendor.
     *   Sent | PartiallyReceived       holds its FULL ORDERED quantity. The
     *                                  vendor is expected to deliver all of
     *                                  it, so all of it is spoken for.
     *   Closed | Cancelled             holds only what was RECEIVED AND
     *                                  ACCEPTED BY QUALITY. The order is
     *                                  over; whatever never arrived, or
     *                                  arrived and was rejected, was never
     *                                  delivered and goes back to the
     *                                  requisition's Balance to Order.
     *
     * THE HOLD FOLLOWS THE MATERIAL, NOT THE PAPERWORK. That one sentence
     * is the whole rule, and it is why Balance to Order can RISE when an
     * order closes short — a vendor's shortfall costs the factory no
     * requisition. This SUPERSEDES the earlier rule (DEC-20260831-002) under
     * which a sent-and-cancelled order kept its full allowance.
     *
     * `purchase_orders.sent_at` is no longer consulted here. It was
     * load-bearing under the superseded rule, which had to tell a cancelled
     * draft from a cancelled sent order; this rule reaches the same answer
     * for a better reason — an unsent order received nothing, so it holds
     * nothing, whatever its status says. The column stays as the lifecycle
     * fact it is and is simply not part of this arithmetic any more.
     *
     * @return array<int, string> [item_id => quantity]
     */
    public function heldByItem(PurchaseOrder $order): array
    {
        // The rule stated outright, and a fast path — not a load-bearing
        // branch. Removing it gives the same answer today, because a draft
        // cannot have receipts (GoodsReceiptService books only against Sent |
        // PartiallyReceived) so the accepted-quantity path below returns zero
        // for one anyway. It stays because "a draft holds nothing" is the
        // owner's rule and should be legible as a rule, and because it saves
        // walking a draft's receipts to prove there are none.
        if ($order->status === PurchaseOrderStatus::Draft) {
            return [];
        }

        $open = in_array($order->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived], true);
        $held = [];

        foreach ($order->lines as $line) {
            $quantity = $open ? (string) $line->quantity : $this->acceptedOnLine($line);
            $itemId = (int) $line->item_id;
            $held[$itemId] = bcadd($held[$itemId] ?? '0', $quantity, self::SCALE);
        }

        return $held;
    }

    /**
     * What Quality ACCEPTED against one purchase-order line, across every
     * arrival booked to it.
     *
     * A receipt line that has NOT been inspected counts as accepted in full,
     * and that is deliberate rather than an oversight: the material is
     * physically in the store and on the books, so the requisition has been
     * answered by it. Treating uninspected stock as un-delivered would hand
     * the buyer back balance for material they are standing next to. Where an
     * inspection DOES exist its verdict governs, and a line inspected more
     * than once (a re-inspection after rework) sums its accepted quantities —
     * incomingInspections is a hasMany and nothing constrains it to one.
     */
    private function acceptedOnLine(PurchaseOrderLine $line): string
    {
        $accepted = '0';

        foreach ($line->goodsReceiptNoteLines as $receiptLine) {
            $inspections = $receiptLine->incomingInspections;

            if ($inspections->isEmpty()) {
                $accepted = bcadd($accepted, (string) $receiptLine->quantity, self::SCALE);

                continue;
            }

            foreach ($inspections as $inspection) {
                $accepted = bcadd($accepted, (string) $inspection->accepted_quantity, self::SCALE);
            }
        }

        return $accepted;
    }

    /**
     * The orders whose hold is worth computing — everything but a Draft.
     * Draft is excluded in SQL because it can never contribute, so the query
     * need not load its receipts; every other status is loaded and asked
     * heldByItem(), which is the only place the amounts are decided.
     *
     * @template TQuery of Builder<PurchaseOrder>|Relation<PurchaseOrder, PurchaseRequisition, *>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scopeHolding(Builder|Relation $query): Builder|Relation
    {
        return $query->where('status', '!=', PurchaseOrderStatus::Draft->value);
    }

    /** The three words a requisition line's coverage is reported in. */
    public const NOT_ORDERED = 'not_ordered';

    public const PARTIALLY_ORDERED = 'partially_ordered';

    public const FULLY_ORDERED = 'fully_ordered';

    /**
     * What every reserving order raised from this requisition has ordered,
     * per item — `[item_id => quantity]`, quantities as bcmath strings.
     * Items the requisition never asked for appear too: an order may carry a
     * line the requisition does not, and hiding it here would hide it from
     * the refusal as well.
     *
     * Reads the loaded `purchaseOrders.lines` when the caller eager-loaded
     * them (PurchaseRequisitionService::WITH does) and queries otherwise, so
     * a list of 20 requisitions costs the same two queries as before.
     *
     * @return array<int, string>
     */
    public function orderedByItem(PurchaseRequisition $requisition): array
    {
        $ordered = [];

        foreach ($this->holdingOrders($requisition) as $order) {
            foreach ($this->heldByItem($order) as $itemId => $quantity) {
                $ordered[$itemId] = bcadd($ordered[$itemId] ?? '0', $quantity, self::SCALE);
            }
        }

        return $ordered;
    }

    /**
     * What this requisition asked for, per item — `[item_id => quantity]`.
     * Grouped, because two lines may name the same item.
     *
     * @return array<int, string>
     */
    public function requestedByItem(PurchaseRequisition $requisition): array
    {
        $requested = [];

        foreach ($this->requisitionLines($requisition) as $line) {
            $itemId = (int) $line->item_id;
            $requested[$itemId] = bcadd(
                $requested[$itemId] ?? '0',
                (string) $line->quantity,
                self::SCALE,
            );
        }

        return $requested;
    }

    /**
     * The four figures and the word, PER REQUISITION LINE — keyed by line id:
     * `{requested_quantity, ordered_quantity, balance_quantity, order_status}`.
     *
     * The join is per ITEM (class note), so when a requisition repeats an
     * item its ordered quantity has to be shared out between those lines to
     * be reported per line at all. It is allocated GREEDILY IN LINE ORDER —
     * the first line is filled before the second gets any. Nothing in the
     * data says which of two identical asks an order answered, and any other
     * split (pro-rata, last-first) would be the same guess wearing a more
     * elaborate hat; in line order at least the buyer can predict it, and
     * the ITEM's totals — the figures the refusal actually enforces — are
     * identical under every split.
     *
     * balance_quantity never goes below zero. It can only be driven there by
     * an order the refusal does not police (a Tally mirror — see
     * PurchaseOrderService::guardRequisitionCoverage), and a negative
     * "still to order" is not a thing a buyer can act on. The overshoot stays
     * visible in the ordered figure standing beside the requested one.
     *
     * @return array<int, array{requested_quantity: string, ordered_quantity: string, balance_quantity: string, order_status: string}>
     */
    public function byLine(PurchaseRequisition $requisition): array
    {
        $remainingPerItem = $this->orderedByItem($requisition);
        $byLine = [];

        foreach ($this->requisitionLines($requisition) as $line) {
            $itemId = (int) $line->item_id;
            $requested = $this->scaled((string) $line->quantity);
            $pool = $remainingPerItem[$itemId] ?? '0.0000';

            // Greedily: this line takes min(its ask, what is left of the item's
            // ordered quantity), and leaves the rest to the lines after it.
            $allocated = bccomp($pool, $requested, self::SCALE) > 0 ? $requested : $this->scaled($pool);
            $remainingPerItem[$itemId] = bcsub($pool, $allocated, self::SCALE);

            $byLine[(int) $line->id] = [
                'requested_quantity' => $requested,
                'ordered_quantity' => $allocated,
                'balance_quantity' => bcsub($requested, $allocated, self::SCALE),
                'order_status' => $this->statusFor($allocated, $requested),
            ];
        }

        return $byLine;
    }

    /**
     * Where THIS requisition is over-ordered, per item — the refusal's
     * evidence, empty when every item is within its ask. One entry per
     * offending item: `{item_id, requested, ordered, excess}`.
     *
     * An item an order carries that the requisition never asked for has a
     * requested of 0, so ANY quantity of it is an excess. That is the rule
     * read plainly — "the combined active PO quantity must never exceed the
     * requested quantity", and nothing was requested — and it is also the
     * only reading that keeps the requisition meaningful: an order that may
     * add items freely is not answering a requisition, it is ignoring one.
     * A buyer who needs something the requisition does not name raises it
     * without a requisition link, or amends the requisition.
     *
     * @return list<array{item_id: int, requested: string, ordered: string, excess: string}>
     */
    public function overOrderedItems(PurchaseRequisition $requisition): array
    {
        $requested = $this->requestedByItem($requisition);
        $over = [];

        foreach ($this->orderedByItem($requisition) as $itemId => $ordered) {
            $ask = $requested[$itemId] ?? '0.0000';

            if (bccomp($ordered, $ask, self::SCALE) > 0) {
                $over[] = [
                    'item_id' => $itemId,
                    'requested' => $this->scaled($ask),
                    'ordered' => $this->scaled($ordered),
                    'excess' => bcsub($ordered, $ask, self::SCALE),
                ];
            }
        }

        return $over;
    }

    /**
     * The requisition's coverage in one word, for a list cell: Fully Ordered
     * only when EVERY line is, Not Ordered when none has been touched,
     * Partially Ordered in between. A requisition with no lines reports Not
     * Ordered — the same word an untouched one gets, because that is what is
     * true of it.
     *
     * Deliberately a word and not a quantity: a requisition's lines may be in
     * Kgs and Nos at once, so it has no total (class note).
     */
    public function requisitionStatus(PurchaseRequisition $requisition): string
    {
        return self::rollUp(array_column($this->byLine($requisition), 'order_status'));
    }

    /**
     * Many line words into one — the roll-up itself, static so
     * PurchaseRequisitionResource can apply it to the lines it has ALREADY
     * been handed without re-reading the orders, and still get the same
     * answer this service would give.
     *
     * @param  list<string>  $lineStatuses
     */
    public static function rollUp(array $lineStatuses): string
    {
        $count = count($lineStatuses);

        if ($count === 0 || $lineStatuses === array_fill(0, $count, self::NOT_ORDERED)) {
            return self::NOT_ORDERED;
        }

        return $lineStatuses === array_fill(0, $count, self::FULLY_ORDERED)
            ? self::FULLY_ORDERED
            : self::PARTIALLY_ORDERED;
    }

    private function statusFor(string $ordered, string $requested): string
    {
        if (bccomp($ordered, '0', self::SCALE) <= 0) {
            return self::NOT_ORDERED;
        }

        return bccomp($ordered, $requested, self::SCALE) >= 0
            ? self::FULLY_ORDERED
            : self::PARTIALLY_ORDERED;
    }

    /**
     * The orders that hold quantity — the loaded relation filtered when the
     * caller eager-loaded it, a query when they did not.
     *
     * @return iterable<int, PurchaseOrder>
     */
    private function holdingOrders(PurchaseRequisition $requisition): iterable
    {
        // What heldByItem() needs to reach Quality's verdict on a terminal
        // order. Loaded in one nested eager load rather than per order.
        $needed = ['lines.goodsReceiptNoteLines.incomingInspections'];

        if ($requisition->relationLoaded('purchaseOrders')) {
            $loaded = $requisition->purchaseOrders
                ->filter(fn (PurchaseOrder $order) => $order->status !== PurchaseOrderStatus::Draft);
            $loaded->loadMissing($needed);

            return $loaded;
        }

        return self::scopeHolding($requisition->purchaseOrders()->with(['lines', ...$needed]))->get();
    }

    /** @return iterable<int, PurchaseRequisitionLine> */
    private function requisitionLines(PurchaseRequisition $requisition): iterable
    {
        return $requisition->relationLoaded('lines')
            ? $requisition->lines
            : $requisition->lines()->orderBy('id')->get();
    }

    /** A quantity at the scale every comparison here uses. */
    private function scaled(string $quantity): string
    {
        return bcadd($quantity, '0', self::SCALE);
    }
}
