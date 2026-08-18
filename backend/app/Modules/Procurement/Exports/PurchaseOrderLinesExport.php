<?php

namespace App\Modules\Procurement\Exports;

use App\Modules\Procurement\Http\Requests\ListPurchaseOrdersRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderLineResource;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

/**
 * GET /procurement/purchase-orders, downloaded ONE ROW PER LINE: every line
 * of every order the list would show for the same filters, in the list's
 * order (then the order's own line order), each line as
 * PurchaseOrderLineResource emits it for this reader beside the order it
 * belongs to (PurchaseOrderResource's header keys, under `purchase_order`).
 *
 * This is where the purchase rate lives — and only for a finance reader:
 * `unit_price` and the derived `amount` are columns of THIS reader's file
 * iff PurchaseOrderLineResource::showsCost() says the screen shows them;
 * for everyone else the two columns are ABSENT, and no row is ever built
 * with a rate the resource did not carry.
 */
class PurchaseOrderLinesExport extends ProcurementExportKind
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function key(): string
    {
        return 'purchase_order_lines';
    }

    public function label(): string
    {
        return 'Purchase order lines';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListPurchaseOrdersRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'purchase_order_id' => 'purchase_order.id',
            'purchase_order_status' => 'purchase_order.status',
            'order_date' => 'purchase_order.order_date',
            'expected_date' => 'purchase_order.expected_date',
            'vendor_code' => 'purchase_order.vendor.code',
            'vendor_name' => 'purchase_order.vendor.name',
            'line_id' => 'id',
            'item_sku' => 'item.sku',
            'item_name' => 'item.name',
            'uom' => 'item.uom',
            'quantity' => 'quantity',
            'quantity_received' => 'quantity_received',
            // FC-06: the resource's own verdict decides whether these exist.
            ...(PurchaseOrderLineResource::showsCost($reader) ? ['unit_price' => 'unit_price', 'amount' => 'amount'] : []),
            'schedules_count' => 'schedules_count',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->orders->cursor($filters) as $order) {
            $header = $this->wire(PurchaseOrderResource::make($order), $request);
            $lines = $header['lines'] ?? [];
            $header = Arr::except($header, ['lines']);

            foreach ($lines as $line) {
                $row = $line;
                $row['purchase_order'] = $header;
                $row['schedules_count'] = count($line['schedules'] ?? []);
                // The resource carried the rate for this reader ⇔ the amount exists.
                if (array_key_exists('unit_price', $line)) {
                    $row['amount'] = $this->amount($line['quantity'] ?? null, $line['unit_price']);
                }

                yield $row;
            }
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->orders->linesCount($filters);
    }
}
