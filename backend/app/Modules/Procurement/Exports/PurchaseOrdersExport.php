<?php

namespace App\Modules\Procurement\Exports;

use App\Modules\Procurement\Http\Requests\ListPurchaseOrdersRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * GET /procurement/purchase-orders, downloaded: one row per order, as
 * PurchaseOrderResource emits it — id (the screen's number), status,
 * source (erp / a Tally mirror) and Tally order number, the vendor by code
 * and name (on every procurement reader's screen, so on the file), the
 * requisition it came from, dates, notes — plus `lines_count`. No rate:
 * the header resource carries none; rates live on purchase_order_lines,
 * for finance eyes.
 */
class PurchaseOrdersExport extends ProcurementExportKind
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function key(): string
    {
        return 'purchase_orders';
    }

    public function label(): string
    {
        return 'Purchase orders';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListPurchaseOrdersRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'status' => 'status',
            'source' => 'source',
            'tally_order_no' => 'tally_order_no',
            'vendor_code' => 'vendor.code',
            'vendor_name' => 'vendor.name',
            'purchase_requisition_id' => 'purchase_requisition_id',
            'order_date' => 'order_date',
            'expected_date' => 'expected_date',
            'lines_count' => 'lines_count',
            'notes' => 'notes',
            'created_at' => 'created_at',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->orders->cursor($filters) as $order) {
            $row = $this->wire(PurchaseOrderResource::make($order), $request);
            $row['lines_count'] = count($row['lines'] ?? []);

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->orders->count($filters);
    }
}
