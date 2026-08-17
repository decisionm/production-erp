<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Http\Resources\PurchaseOrderLineResource;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\TallySync\Services\TallySyncLinkService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * THE CHAIN BEHIND ONE PURCHASE ORDER (Phase 6, P6-02) — where Procurement
 * reaches into the modules that hold the rest of the story, each through
 * its Service class, never its models (CLAUDE.md):
 *
 *   TallySync   TallySyncLinkService — "did the voucher for this order /
 *               this receipt reach Tally" (status + flags + link; never the
 *               payload — a link that carried it would be FC-06's back door);
 *   Inventory   StockMovementService — the ledger line each receipt wrote
 *               (purpose Receipt) and each consuming batch wrote (purpose
 *               Consumption); TraceabilityService — the segment consumption
 *               figure (opening + loaded − closing − returned);
 *   Production  DayBinLedgerService — where each bag was loaded (machine
 *               or the common input) and during which batch segment.
 *
 * The chain, oldest first at every level:
 *   PO (lines, schedules) → receipts (receipt_key, lines, quantities, link)
 *   → each line's stock movement(s) and material lots → bags → loads
 *   → the batch segments those loads fed, with their consumption and issues.
 *
 * FC-06: purchase rates (unit_price, unit_cost, receipt_rate_per_kg, a
 * movement's unit_cost) ride ONLY for a reader PurchaseOrderLineResource::
 * showsCost() admits — the same predicate the order and receipt lists use —
 * and for everyone else the key is ABSENT and a `rate_withheld` note stands
 * where the number would (never a null that could read as "cost nothing").
 * Supplier identity (the vendor) is what every procurement reader already
 * sees on the order itself.
 *
 * FC-01: a bag belongs to no machine and no batch. A load says where the
 * material was POURED (machine, or null = the common resin input) and under
 * which segment it was RECORDED; the consumption block is that segment's
 * figure for that item, not the bag's.
 *
 * Read-only. Touches no Tally, writes no row.
 */
class PurchaseOrderTraceService
{
    /** The note where a rate would stand for a reader the gate turns away — defined once, beside the gate. */
    public const RATE_WITHHELD = PurchaseOrderLineResource::RATE_WITHHELD;

    public function __construct(
        private readonly TallySyncLinkService $tallyLinks,
        private readonly StockMovementService $stock,
        private readonly TraceabilityService $traceability,
        private readonly DayBinLedgerService $dayBin,
    ) {}

    /**
     * Stamp each receipt with its Receipt Note TallyLink (or null) — one
     * query for the whole set. GoodsReceiptService calls this on every row
     * it returns.
     *
     * @param  iterable<int, GoodsReceiptNote>  $receipts
     */
    public function decorateReceipts(iterable $receipts): void
    {
        $receipts = Collection::make($receipts);
        if ($receipts->isEmpty()) {
            return;
        }

        $links = $this->tallyLinks->forMany(
            (new GoodsReceiptNote)->getMorphClass(),
            $receipts->map(fn (GoodsReceiptNote $receipt) => $receipt->id)->all(),
        );

        foreach ($receipts as $receipt) {
            $receipt->tallyLink = $links[$receipt->id] ?? null;
        }
    }

    /**
     * The whole chain for one order, for THIS reader.
     *
     * @return array{purchase_order: array<string, mixed>, lines: list<array<string, mixed>>, receipts: list<array<string, mixed>>, consumption: list<array<string, mixed>>}
     */
    public function orderTrace(PurchaseOrder $order, ?Authenticatable $reader): array
    {
        $showsCost = PurchaseOrderLineResource::showsCost($reader);

        $order->loadMissing([
            'vendor',
            'lines.item',
            'lines.schedules',
            'receipts.warehouse',
            'receipts.lines.item',
            'receipts.lines.materialLots.bags',
        ]);
        $order->tallyLink = $this->tallyLinks->for($order);

        $receipts = $order->receipts->sortBy('id')->values();
        $this->decorateReceipts($receipts);

        // Every bag on every lot of every receipt → its load rows, one query.
        $bagIds = $receipts
            ->flatMap(fn (GoodsReceiptNote $receipt) => $receipt->lines)
            ->flatMap(fn (GoodsReceiptNoteLine $line) => $line->materialLots)
            ->flatMap(fn (MaterialLot $lot) => $lot->bags)
            ->map(fn (MaterialBag $bag) => $bag->id)
            ->all();
        $loadsByBag = $this->dayBin->loadsForBags($bagIds)->groupBy('material_bag_id');

        return [
            'purchase_order' => $this->orderHeader($order),
            'lines' => $order->lines->sortBy('id')->values()
                ->map(fn (PurchaseOrderLine $line) => $this->orderLineRow($line, $showsCost))
                ->all(),
            'receipts' => $receipts
                ->map(fn (GoodsReceiptNote $receipt) => $this->receiptRow($order, $receipt, $loadsByBag, $showsCost))
                ->all(),
            'consumption' => $this->consumptionRows($loadsByBag->flatten(1), $showsCost),
        ];
    }

    // ---- shapes -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function orderHeader(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'document_number' => $order->documentNumber(),
            'status' => $order->status->value,
            'source' => $order->source ?? 'erp',
            'tally_order_no' => $order->tally_order_no,
            'order_date' => $order->order_date?->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'vendor' => $order->vendor === null ? null : [
                'id' => $order->vendor->id,
                'code' => $order->vendor->code,
                'name' => $order->vendor->name,
            ],
            'tally' => $order->tallyLink,
            'tally_staging' => $order->tally_staging,
        ];
    }

    /** @return array<string, mixed> */
    private function orderLineRow(PurchaseOrderLine $line, bool $showsCost): array
    {
        return [
            'id' => $line->id,
            'item' => $this->itemStub($line->item),
            'quantity' => (string) $line->quantity,
            'quantity_received' => (string) $line->quantity_received,
            'remaining' => bcsub((string) $line->quantity, (string) $line->quantity_received, 4),
            ...$this->rate('unit_price', $line->unit_price, $showsCost),
            'schedules' => $line->schedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'due_date' => $schedule->due_date?->toDateString(),
                'quantity' => (string) $schedule->quantity,
                'quantity_received' => (string) $schedule->quantity_received,
                'remaining' => $schedule->remaining(),
                'tally_reference' => $schedule->tally_reference,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, DayBinMovement>>  $loadsByBag
     * @return array<string, mixed>
     */
    private function receiptRow(PurchaseOrder $order, GoodsReceiptNote $receipt, Collection $loadsByBag, bool $showsCost): array
    {
        // The ledger carries no GRN foreign key: GoodsReceiptService stamped
        // each line's movement with the receipt's reference (or "GRN for PO
        // #{id}" when blank) — matched on that, then narrowed to the line's
        // item and the receipt's warehouse.
        $movements = $this->stock
            ->receiptsForReference($receipt->reference ?? "GRN for PO #{$order->id}")
            ->where('warehouse_id', $receipt->warehouse_id);

        return [
            'id' => $receipt->id,
            'document_number' => "GRN-{$receipt->id}",
            'receipt_key' => $receipt->receipt_key,
            'reference' => $receipt->reference,
            'receipt_note_reference' => $receipt->receipt_note_reference,
            'tracking_number' => $receipt->tracking_number,
            'received_date' => $receipt->received_date?->toIso8601String(),
            'warehouse' => $receipt->warehouse === null ? null : [
                'id' => $receipt->warehouse->id,
                'code' => $receipt->warehouse->code,
                'name' => $receipt->warehouse->name,
            ],
            'tally' => $receipt->tallyLink,
            'lines' => $receipt->lines->sortBy('id')->values()->map(fn (GoodsReceiptNoteLine $line) => [
                'id' => $line->id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'item' => $this->itemStub($line->item),
                'quantity' => (string) $line->quantity,
                ...$this->rate('unit_cost', $line->unit_cost, $showsCost),
                'stock_movements' => $movements
                    ->where('item_id', $line->item_id)
                    ->values()
                    ->map(fn (StockMovement $movement) => $this->movementRow($movement, $showsCost))
                    ->all(),
                'material_lots' => $line->materialLots->sortBy('id')->values()->map(fn (MaterialLot $lot) => [
                    'id' => $lot->id,
                    'supplier_lot_no' => $lot->supplier_lot_no,
                    'received_date' => $lot->received_date?->toDateString(),
                    'bag_count' => $lot->bag_count,
                    'total_received_kg' => (string) $lot->total_received_kg,
                    ...$this->rate('receipt_rate_per_kg', $lot->receiptRatePerKg(), $showsCost),
                    'bags' => $lot->bags->sortBy('id')->values()->map(fn (MaterialBag $bag) => [
                        'id' => $bag->id,
                        'barcode' => $bag->barcode,
                        'status' => $bag->status?->value,
                        'original_kg' => (string) $bag->original_kg,
                        'remaining_kg' => (string) $bag->remaining_kg,
                        'loads' => ($loadsByBag->get($bag->id) ?? collect())
                            ->map(fn (DayBinMovement $load) => $this->loadRow($load))
                            ->values()
                            ->all(),
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function movementRow(StockMovement $movement, bool $showsCost): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'purpose' => $movement->purpose?->value,
            'quantity' => (string) $movement->quantity,
            ...$this->rate('unit_cost', $movement->unit_cost, $showsCost),
            'reference' => $movement->reference,
            'warehouse_id' => $movement->warehouse_id,
            'movement_date' => $movement->movement_date?->toIso8601String(),
        ];
    }

    /**
     * Where a bag was poured and under which segment it was recorded —
     * machine null = the common resin input; segment null = outside any
     * batch window (FC-01: neither is ownership).
     *
     * @return array<string, mixed>
     */
    private function loadRow(DayBinMovement $load): array
    {
        return [
            'id' => $load->id,
            'work_center' => $this->workCenterStub($load->workCenter),
            'shift_production_entry_id' => $load->shift_production_entry_id,
            'batch_number' => $load->shiftProductionEntry?->batch_number,
            'quantity_kg' => (string) $load->quantity_kg,
            'recorded_at' => $load->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * One row per (batch segment, item) the order's bags were loaded under:
     * the segment's day-bin consumption figure (TraceabilityService::
     * consumptionFor — the design formula, null consumed_kg until a closing
     * count exists), how many kg of THIS order's bags were loaded under it,
     * and the Consumption issues the batch's completion wrote for the item
     * (StockMovementService::issuesForReference "SPE #{id}"). Loads outside
     * any segment (the common input, a store top-up) have no segment to
     * consume against and appear only on their bag.
     *
     * @param  Collection<int, DayBinMovement>  $loads
     * @return list<array<string, mixed>>
     */
    private function consumptionRows(Collection $loads, bool $showsCost): array
    {
        $rows = [];

        foreach ($loads->sortBy('id') as $load) {
            $entryId = $load->shift_production_entry_id;
            if ($entryId === null || $load->shiftProductionEntry === null) {
                continue;
            }
            $key = "{$entryId}:{$load->item_id}";

            if (! isset($rows[$key])) {
                $entry = $load->shiftProductionEntry;
                $rows[$key] = [
                    'shift_production_entry' => [
                        'id' => $entry->id,
                        'batch_number' => $entry->batch_number,
                        'batch_status' => $entry->batch_status?->value,
                        'production_date' => $entry->production_date?->toDateString(),
                        'work_center' => $this->workCenterStub($entry->workCenter),
                    ],
                    'item' => $this->itemStub($load->item),
                    'loaded_kg_from_this_order' => '0.0000',
                    'day_bin' => $this->traceability->consumptionFor($entryId, (int) $load->item_id),
                    'stock_issues' => $this->stock->issuesForReference("SPE #{$entryId}")
                        ->where('item_id', $load->item_id)
                        ->values()
                        ->map(fn (StockMovement $movement) => $this->movementRow($movement, $showsCost))
                        ->all(),
                ];
            }

            $rows[$key]['loaded_kg_from_this_order'] = bcadd($rows[$key]['loaded_kg_from_this_order'], (string) $load->quantity_kg, 4);
        }

        return array_values($rows);
    }

    /**
     * The rate key for THIS reader: the number for finance eyes, ABSENT plus
     * a `rate_withheld` note for everyone else (FC-06). One place, so every
     * rate on the trace is gated by the same rule.
     *
     * @return array<string, mixed>
     */
    private function rate(string $key, mixed $value, bool $showsCost): array
    {
        return $showsCost
            ? [$key => $value === null ? null : (string) $value]
            : ['rate_withheld' => self::RATE_WITHHELD];
    }

    /** @return array{id: int, sku: ?string, name: string, uom: ?string}|null */
    private function itemStub($item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'uom' => $item->uom];
    }

    /** @return array{id: int, code: ?string, name: string}|null */
    private function workCenterStub($workCenter): ?array
    {
        return $workCenter === null ? null : ['id' => $workCenter->id, 'code' => $workCenter->code, 'name' => $workCenter->name];
    }
}
