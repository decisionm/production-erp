<?php

namespace App\Modules\Sales\Exports;

use App\Modules\Sales\Http\Requests\ListDeliveriesRequest;
use App\Modules\Sales\Http\Resources\DeliveryResource;
use App\Modules\Sales\Services\DeliveryService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * GET /sales/deliveries, downloaded: one row per delivery, as
 * DeliveryResource emits it for this reader — number, the order it fulfils,
 * customer, warehouse, reference, the factory-stamped delivered_date, the
 * scanned-carton count, its Delivery Note TallyLink (status, voucher
 * number, synced_at — the link's public tier, never the payload), notes —
 * plus `lines_count`.
 */
class DeliveriesExport extends SalesExportKind
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function key(): string
    {
        return 'deliveries';
    }

    public function label(): string
    {
        return 'Deliveries';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListDeliveriesRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'document_number' => 'document_number',
            'sales_order_number' => 'sales_order.document_number',
            'sales_order_status' => 'sales_order.status',
            'customer_code' => 'customer.code',
            'customer_name' => 'customer.name',
            'warehouse_code' => 'warehouse.code',
            'warehouse_name' => 'warehouse.name',
            'reference' => 'reference',
            'delivered_date' => 'delivered_date',
            'lines_count' => 'lines_count',
            'carton_count' => 'carton_count',
            'tally_status' => 'tally.status',
            'tally_voucher_number' => 'tally.voucher_number',
            'tally_synced_at' => 'tally.synced_at',
            'notes' => 'notes',
            'created_at' => 'created_at',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->deliveries->cursor($filters) as $delivery) {
            $row = $this->wire(DeliveryResource::make($delivery), $request);
            $row['lines_count'] = count($row['lines'] ?? []);

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->deliveries->count($filters);
    }
}
