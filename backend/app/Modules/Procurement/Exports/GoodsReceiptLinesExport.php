<?php

namespace App\Modules\Procurement\Exports;

use App\Modules\Procurement\Http\Requests\ListGoodsReceiptsRequest;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteLineResource;
use App\Modules\Procurement\Http\Resources\GoodsReceiptNoteResource;
use App\Modules\Procurement\Services\GoodsReceiptService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

/**
 * GET /procurement/goods-receipts, downloaded ONE ROW PER LINE: every line
 * of every receipt the list would show for the same filters, in the list's
 * order (then the receipt's own line order), each line as
 * GoodsReceiptNoteLineResource emits it for this reader beside the receipt
 * it belongs to (GoodsReceiptNoteResource's header keys, under
 * `goods_receipt_note`).
 *
 * This is where the receipt rate lives — and only for a finance reader:
 * `unit_cost` and the derived `amount` are columns of THIS reader's file
 * iff GoodsReceiptNoteLineResource::showsCost() says the screen shows
 * them; for everyone else the two columns are ABSENT, and no row is ever
 * built with a rate the resource did not carry.
 */
class GoodsReceiptLinesExport extends ProcurementExportKind
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function key(): string
    {
        return 'goods_receipt_lines';
    }

    public function label(): string
    {
        return 'Goods receipt lines';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListGoodsReceiptsRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'goods_receipt_note_id' => 'goods_receipt_note.id',
            'purchase_order_id' => 'goods_receipt_note.purchase_order_id',
            'received_date' => 'goods_receipt_note.received_date',
            'warehouse_code' => 'goods_receipt_note.warehouse.code',
            'warehouse_name' => 'goods_receipt_note.warehouse.name',
            'reference' => 'goods_receipt_note.reference',
            'line_id' => 'id',
            'purchase_order_line_id' => 'purchase_order_line_id',
            'item_sku' => 'item.sku',
            'item_name' => 'item.name',
            'uom' => 'item.uom',
            'quantity' => 'quantity',
            // FC-06: the resource's own verdict decides whether these exist.
            ...(GoodsReceiptNoteLineResource::showsCost($reader) ? ['unit_cost' => 'unit_cost', 'amount' => 'amount'] : []),
            'material_lots_count' => 'material_lots_count',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->receipts->cursor($filters) as $receipt) {
            $header = $this->wire(GoodsReceiptNoteResource::make($receipt), $request);
            $lines = $header['lines'] ?? [];
            $header = Arr::except($header, ['lines', 'material_lots']);

            foreach ($lines as $line) {
                $row = $line;
                $row['goods_receipt_note'] = $header;
                $row['material_lots_count'] = count($line['material_lots'] ?? []);
                // The resource carried the rate for this reader ⇔ the amount exists.
                if (array_key_exists('unit_cost', $line)) {
                    $row['amount'] = $this->amount($line['quantity'] ?? null, $line['unit_cost']);
                }

                yield $row;
            }
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->receipts->linesCount($filters);
    }
}
