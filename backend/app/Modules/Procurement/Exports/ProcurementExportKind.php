<?php

namespace App\Modules\Procurement\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the four Procurement kinds share (Phase 4.5): the module and its
 * permission pair, the list request's grammar minus its paging, the
 * resource-to-row step, and the one piece of arithmetic a line row adds.
 *
 * Every Procurement kind is a Procurement list, downloaded — GET
 * /procurement/{list} with the SAME filters (ListPurchaseOrdersRequest /
 * ListGoodsReceiptsRequest, delegated), the SAME query and order (the
 * service's cursor() beside paginate()), and every row built THROUGH the
 * list's JsonResource for THIS reader.
 *
 * FC-06 ON THE FILE — exactly as on the screen. The purchase rate is
 * Owner/Accounts data: PurchaseOrderLineResource / GoodsReceiptNoteLineResource
 * serve `unit_price` / `unit_cost` ONLY to a finance.view/finance.manage
 * reader and OMIT the key (never null it) for everyone else. The line kinds
 * ask those resources' own predicate (::showsCost) whether the rate and
 * amount columns exist for this reader — ABSENT from the file, not blank
 * (a blank would read as "this resin cost nothing") — and a row's amount is
 * computed only where the resource actually carried the rate. The header
 * kinds carry no rate at all: the resources put none on a header. Supplier
 * identity follows the resource too: a purchase order names its vendor to
 * every procurement reader on screen, so its file does; a receipt names
 * only its order (GoodsReceiptNoteResource carries no vendor), so its file
 * names only the order.
 */
abstract class ProcurementExportKind extends AbstractExportKind
{
    public function module(): string
    {
        return 'procurement';
    }

    public function permissionAny(): array
    {
        return ['procurement.view', 'procurement.manage'];
    }

    /**
     * quantity × rate, the line's amount as the purchase-order screen shows
     * it (PurchaseOrdersPage lineAmount: two decimals, rounded) — computed
     * ONLY when the resource carried the rate for this reader; null when it
     * did not (the column is absent then anyway) or when either side is
     * missing. Exact decimal arithmetic (bcmath), half-up at two places;
     * quantities and rates are never negative here.
     */
    protected function amount(mixed $quantity, mixed $rate): ?string
    {
        if (! is_numeric($quantity) || ! is_numeric($rate)) {
            return null;
        }

        return bcadd(bcmul((string) $quantity, (string) $rate, 8), '0.005', 2);
    }
}
