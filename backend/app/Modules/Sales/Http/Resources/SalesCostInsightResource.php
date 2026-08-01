<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Services\SalesCostInsightService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WHAT AN ORDER COSTS, and how sure we are of it.
 *
 * THE ANATOMY IS FINANCE'S. Costs and margins are for anyone with sales
 * access — that is the whole feature — but the per-component breakdown behind
 * them (per-kg rates, bag barcodes, supplier lot numbers) rides
 * finance.view/manage, and the `components` key is ABSENT rather than nulled
 * for everyone else.
 *
 * Absent matters here for the reason MaterialLotResource spells out: null is
 * a REAL state in this payload (a material with no recorded rate is genuinely
 * null), so nulling the breakdown for a salesperson would be
 * indistinguishable from telling them the factory has no rates at all. The
 * gate is the module-coarse pair this codebase already uses — inventing a
 * per-field permission would mint one PermissionSeeder strips on the next
 * deploy, silently opening it.
 *
 * `sources` carries the same explanations in words with no bag or supplier
 * identity in them, and is present for everyone: a salesperson is never shown
 * a figure they cannot account for, only one they cannot trace to a supplier.
 */
class SalesCostInsightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(SalesCostInsightService::class)->forOrder(
            $this->resource,
            withIdentity: (bool) $request->user()?->canAny(['finance.view', 'finance.manage']),
        );
    }
}
