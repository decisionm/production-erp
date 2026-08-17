<?php

namespace App\Modules\Procurement\Exports;

use App\Modules\Procurement\Http\Requests\ListGoodsReceiptsRequest;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteResource;
use App\Modules\Procurement\Services\GoodsReceiptService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * GET /procurement/goods-receipts, downloaded: one row per receipt, as
 * GoodsReceiptNoteResource emits it — id (the screen's number), the order
 * it arrived against, warehouse, the receipt's own references (the typed
 * reference, the Receipt Note reference, the tracking number), the
 * received_date instant, notes — plus `lines_count` and
 * `material_lots_count`. No rate and no vendor: the header resource carries
 * neither (the screen shows "PO #n"); rates live on goods_receipt_lines,
 * for finance eyes.
 */
class GoodsReceiptsExport extends ProcurementExportKind
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function key(): string
    {
        return 'goods_receipts';
    }

    public function label(): string
    {
        return 'Goods receipts';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListGoodsReceiptsRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'purchase_order_id' => 'purchase_order_id',
            'warehouse_code' => 'warehouse.code',
            'warehouse_name' => 'warehouse.name',
            'reference' => 'reference',
            'receipt_note_reference' => 'receipt_note_reference',
            'tracking_number' => 'tracking_number',
            'received_date' => 'received_date',
            'lines_count' => 'lines_count',
            'material_lots_count' => 'material_lots_count',
            'notes' => 'notes',
            'created_at' => 'created_at',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->receipts->cursor($filters) as $receipt) {
            $row = $this->wire(GoodsReceiptNoteResource::make($receipt), $request);
            $row['lines_count'] = count($row['lines'] ?? []);
            $row['material_lots_count'] = count($row['material_lots'] ?? []);

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->receipts->count($filters);
    }
}
