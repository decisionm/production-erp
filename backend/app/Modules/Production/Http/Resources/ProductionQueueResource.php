<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Production\Models\ProductionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the PRODUCTION QUEUE — a production request, the demand behind
 * it, and the date in front of it.
 *
 * ONE CLASS OWNS THE REQUEST'S WIRE KEYS, and it is not this one.
 * `id`, `request_number`, `priority`, `status`, `item`, `quantity`,
 * `sales_order_line_id`, `sales_order`, `can` and the rest are spread from
 * ProductionRequestResource — the same shape `/production/requests` returns,
 * under the same names. A second hand-rolled serialization of an entity that
 * already has a resource is how two screens end up disagreeing about what a
 * request is called; this class adds the JOIN and nothing else.
 *
 * FIELD-LEVEL GATING, and here is why it is here rather than in the service.
 *
 * This route is OR-gated `module:production,inventory` (P3) so both desks can
 * read the one document they share. That gate says who may open the QUEUE. It
 * does not say who may read the figures this row JOINS ON, which belong to
 * other modules and are refused elsewhere:
 *
 *   the planning block  `/inventory/fulfilment/planning`, module:inventory
 *   ordered/delivered   `/inventory/fulfilment/queue` (FulfilmentQueueRow-
 *                       Resource) for the store, and Sales' own reads
 *   expected_date       `/sales/sales-orders` and the dashboard's `demand`
 *                       block — module:sales, and NOWHERE else today
 *
 * So each block is gated by the module that owns it, exactly as
 * DashboardService::summary() gates its own composed blocks with
 * `$sees('sales')` / `$sees('inventory')` — the established answer in this
 * codebase for a screen that composes several modules. THE RULE HERE IS: a
 * caller sees on this row only what they could already read elsewhere. No
 * refusal that stood yesterday stops standing because this endpoint exists.
 *
 * Whether the FLOOR should be given more than that — its own ETA, its own
 * free-stock figure, the customer's date — is the owner's call, not a
 * builder's, and it is asked in docs/factory/PENDING-OWNER-QUESTIONS.md.
 * Until it is answered the conservative shape ships.
 *
 * OMITTED, NEVER NULLED — the MaterialLotResource rule, and it matters more
 * here than there. `cannot_estimate` is a REAL state: the factory genuinely
 * cannot date this row. A null planning block for an ungated caller would be
 * indistinguishable from that refusal, and the screen would print "cannot
 * estimate" at a person who is merely not allowed to see the answer. Absent
 * means "not yours to read"; present-and-refusing means "nobody knows".
 *
 * FC-06: no rate, no cost, no vendor — the sales order line's unit_price is
 * deliberately not read on either side of any gate. This is the floor's
 * screen and the store's, and neither is Accounts.
 */
class ProductionQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{request: ProductionRequest, ordered: ?string, delivered: ?string, expected_date: ?string, planning: ?array<string, mixed>} $row */
        $row = $this->resource;

        $user = $request->user();
        // The STORE's own reads: the fulfilment queue and the planning walk.
        $seesStore = $user?->hasAnyPermission(['inventory.view', 'inventory.manage']) ?? false;
        // The SALES desk's reads: the order, its dates, its line figures.
        $seesSales = $user?->hasAnyPermission(['sales.view', 'sales.manage']) ?? false;

        // The request half, resolved ONCE — every key below that is not a
        // join field comes from here and is named whatever that class names it.
        $base = ProductionRequestResource::make($row['request'])->toArray($request);

        return [
            ...$base,

            // WHAT THE CUSTOMER ORDERED, and what has already gone out — the
            // denominator the request quantity is a part of. Both desks that
            // already read it on the store's fulfilment queue keep reading it.
            ...($seesStore || $seesSales ? [
                'ordered' => $row['ordered'],
                'delivered' => $row['delivered'],
            ] : []),

            // THE ORDER'S EXPECTED DATE, added to the sales_order stub
            // ProductionRequestResource already shapes. Sales-only: it is
            // reachable today from no other desk. Nullable there and null
            // here rather than filled in from the order date — an order with
            // no expected date has not been given one.
            ...($seesSales && $base['sales_order'] !== null ? [
                'sales_order' => [
                    ...$base['sales_order'],
                    'expected_date' => $row['expected_date'],
                ],
            ] : []),

            // WHEN THE FACTORY COULD HAVE IT, whole or absent — never half.
            // free / queued_ahead / capacity_per_shift / shifts_needed /
            // estimated_ready_date / cannot_estimate / reason, exactly as
            // FulfilmentPlanningService computed them, including its
            // cannot_estimate CASCADE (S12). Kept as ONE block because a
            // half-gated block is harder to reason about than a whole one:
            // `queued_ahead` alone is derivable from the ungated request
            // queue's priorities, and including it here is conservatism
            // rather than a claim about what the caller may know.
            ...($seesStore ? ['planning' => $row['planning']] : []),
        ];
    }
}
