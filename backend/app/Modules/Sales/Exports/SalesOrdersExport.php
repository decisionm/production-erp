<?php

namespace App\Modules\Sales\Exports;

use App\Modules\Sales\Http\Requests\ListSalesOrdersRequest;
use App\Modules\Sales\Http\Resources\SalesOrderResource;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * GET /sales/sales-orders, downloaded: one row per order, as
 * SalesOrderResource emits it for this reader — number, status, customer,
 * dates, the model's own totals and counts, the cancel verdict, notes —
 * plus `lines_count` (how many lines the resource carried, the screen's
 * "Lines" column).
 */
class SalesOrdersExport extends SalesExportKind
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public function key(): string
    {
        return 'sales_orders';
    }

    public function label(): string
    {
        return 'Sales orders';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListSalesOrdersRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'document_number' => 'document_number',
            'status' => 'status',
            'customer_code' => 'customer.code',
            'customer_name' => 'customer.name',
            'order_date' => 'order_date',
            'expected_date' => 'expected_date',
            'ordered_quantity' => 'totals.ordered_quantity',
            'delivered_quantity' => 'totals.delivered_quantity',
            'invoiced_quantity' => 'totals.invoiced_quantity',
            'lines_count' => 'lines_count',
            'deliveries_count' => 'deliveries_count',
            'invoices_count' => 'invoices_count',
            'can_cancel' => 'can_cancel',
            'notes' => 'notes',
            'created_at' => 'created_at',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->orders->cursor($filters) as $order) {
            $row = $this->wire(SalesOrderResource::make($order), $request);
            $row['lines_count'] = count($row['lines'] ?? []);

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->orders->count($filters);
    }
}
