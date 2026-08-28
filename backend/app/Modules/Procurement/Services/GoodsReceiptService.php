<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Exceptions\OverReceiptException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Posting a GRN is the one place Procurement actually moves stock. It never
 * touches Inventory's tables directly — it goes through StockMovementService,
 * the same as any other caller of that module, so Inventory's valuation and
 * balance-locking logic stays in one place.
 */
class GoodsReceiptService
{
    /** Loaded on every receipt the list hands back, so the resource never lazy-loads. */
    private const WITH = [
        'lines.item',
        'lines.incomingInspections',
        'lines.materialLots.item',
        'lines.materialLots.bags',
        'lines.materialLots.costVersions',
        'materialLots.item',
        'materialLots.bags',
        'materialLots.costVersions',
        'warehouse',
        'purchaseOrder.vendor',
    ];

    /** How many receipts cursor() reads per query — a page of the export, never the whole file. */
    private const EXPORT_CHUNK = 500;

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly TraceabilityService $traceability,
        private readonly ProcurementDocumentQuery $query,
        private readonly PurchaseOrderTraceService $trace,
    ) {}

    /**
     * The list, filtered (Phase 4.5, mirroring Sales' Phase 3.5 lists).
     * $filters is the validated ListGoodsReceiptsRequest input; an empty
     * array is the unfiltered list every earlier caller still gets — newest
     * first, same page size.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        // withQueryString(): the paginator's own links carry the request's
        // query string, so an API client walking links.next stays on the
        // same query (as the Sales lists do). Every row stamped with its
        // Receipt Note TallyLink (one query for the page).
        $page = $this->listQuery($filters)->paginate($perPage)->withQueryString();
        $this->trace->decorateReceipts($page->getCollection());

        return $page;
    }

    /**
     * Every matching receipt, in the list's order, one at a time — the
     * Export Center's read (GoodsReceiptsExport / GoodsReceiptLinesExport):
     * the SAME filters and the SAME ordering as paginate(), off the same
     * builder, so a file can never carry rows the screen would not, nor in
     * another order. Builder::lazy, not Builder::cursor(): cursor() skips
     * the eager loads the resource prints.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, GoodsReceiptNote>
     */
    public function cursor(array $filters = []): LazyCollection
    {
        return $this->listQuery($filters)
            ->lazy(self::EXPORT_CHUNK)
            ->chunk(self::EXPORT_CHUNK)
            ->flatMap(function (LazyCollection $chunk): LazyCollection {
                $this->trace->decorateReceipts($chunk);

                return $chunk;
            });
    }

    /**
     * One receipt as the show endpoint returns it (Phase 6, P6-02): the
     * list's relations (lines with their lots and bags, the store, the
     * order and its vendor) plus its Receipt Note TallyLink. The chain
     * around it — the order, the other arrivals, where the bags went — is
     * GET purchase-orders/{po}/trace.
     */
    public function show(GoodsReceiptNote $receipt): GoodsReceiptNote
    {
        $receipt->load(self::WITH);
        $this->trace->decorateReceipts([$receipt]);

        return $receipt;
    }

    /**
     * How many receipts the list would carry — one COUNT over the filtered
     * query (the export's cap check; also the list's meta.total).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * How many LINES the matching receipts carry between them — one COUNT
     * with the filtered receipts as a subquery (the line export's cap check).
     *
     * @param  array<string, mixed>  $filters
     */
    public function linesCount(array $filters = []): int
    {
        return GoodsReceiptNoteLine::query()
            ->whereIn('goods_receipt_note_id', $this->filtered($filters)->select('goods_receipt_notes.id'))
            ->count();
    }

    // ---- the list's query ---------------------------------------------------------

    /**
     * The list's builder: every filter applied, the relations the resource
     * prints, then the list's order — what paginate() pages and cursor()
     * streams.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->filtered($filters)->with(self::WITH);
        $this->query->applySort($query, $filters['sort'] ?? null, ['received_date']);

        return $query;
    }

    /**
     * The filtered receipts, nothing loaded and nothing ordered — the one
     * builder listQuery(), count() and linesCount() all start from.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = GoodsReceiptNote::query();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Every filter of ListGoodsReceiptsRequest. The vendor is the ORDER's
     * vendor (a receipt names no vendor of its own). `q` matches the
     * receipt number in any spelling ("GRN-7", "grn 7", "7"), the receipt's
     * reference (as a delivery's is matched), or the order's vendor by name
     * or code — never notes. The date range is FACTORY days on
     * received_date (a datetime), exactly as a delivery's delivered_date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['vendor_id'])) {
            $query->whereHas('purchaseOrder', fn (Builder $order) => $order->where('vendor_id', (int) $filters['vendor_id']));
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        }

        $this->query->applyFactoryDayRange($query, 'received_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'GRN');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('goods_receipt_notes.id', $id);
                }
                $any->orWhere(fn (Builder $reference) => $this->query->whereLike($reference, 'reference', $term));
                $any->orWhereHas('purchaseOrder.vendor', fn (Builder $vendor) => $this->query->whereVendorMatches($vendor, $term));
            });
        }
    }

    /**
     * @param  array{
     *     receipt_key?: string, purchase_order_id: int, warehouse_id: int,
     *     reference?: string, received_date?: string, notes?: string,
     *     lines: array<int, array{
     *         purchase_order_line_id: int, quantity: string, unit_cost?: string,
     *         lots?: array<int, array{
     *             supplier_lot_no?: ?string, bag_count: int,
     *             bag_weight_kg?: string|float|null,
     *             total_received_kg?: string|float|null,
     *             barcodes?: array<int, string>,
     *             bag_weights?: array<int, string|float>, notes?: ?string,
     *         }>,
     *     }>,
     * }  $data
     */
    public function create(array $data, ?int $createdBy): GoodsReceiptNote
    {
        $receiptKey = isset($data['receipt_key']) ? trim((string) $data['receipt_key']) : null;
        $payloadHash = $receiptKey !== null ? $this->payloadHash($data) : null;

        if ($receiptKey !== null) {
            $existing = GoodsReceiptNote::query()->where('receipt_key', $receiptKey)->first();
            if ($existing !== null) {
                return $this->replay($existing, $payloadHash);
            }
        }

        try {
            return DB::transaction(function () use ($data, $createdBy, $receiptKey, $payloadHash) {
                if ($receiptKey !== null) {
                    $existing = GoodsReceiptNote::query()
                        ->where('receipt_key', $receiptKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null) {
                        return $this->replay($existing, $payloadHash);
                    }
                }

                // The PO is the receipt mutex. Two different receipt keys
                // must not both read the same remaining quantity and post
                // stock before either updates quantity_received.
                $order = PurchaseOrder::query()
                    ->with('lines.item')
                    ->lockForUpdate()
                    ->findOrFail($data['purchase_order_id']);

                if (! in_array($order->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived], true)) {
                    throw InvalidStatusTransitionException::make('purchase order', $order->status->value, 'received');
                }

                $data = $this->prepareLotManifests($order, $data);

                $grn = GoodsReceiptNote::create([
                    'receipt_key' => $receiptKey,
                    'receipt_payload_hash' => $payloadHash,
                    'purchase_order_id' => $order->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'reference' => $data['reference'] ?? null,
                    'received_date' => $data['received_date'] ?? now(),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $createdBy,
                    // Recorded at PHYSICAL ARRIVAL (owner-confirmed): the
                    // reference this arrival's Receipt Note — and any later
                    // Rejections Out — will carry against the Tally PO. A
                    // blank gets a deterministic internal one so every
                    // arrival is referenceable.
                    'receipt_note_reference' => $data['receipt_note_reference'] ?? null,
                    'tracking_number' => $data['tracking_number'] ?? null,
                ]);

                if ($grn->receipt_note_reference === null) {
                    $grn->update(['receipt_note_reference' => sprintf('RN-%05d', $grn->id)]);
                }
                if ($grn->tracking_number === null) {
                    $grn->update(['tracking_number' => sprintf('GRN-%05d', $grn->id)]);
                }

                foreach ($data['lines'] as $lineData) {
                    // Form request validation ties purchase_order_line_id to this
                    // purchase_order_id. The explicit service check in
                    // prepareLotManifests protects direct/internal callers too.
                    $poLine = $order->lines->firstWhere('id', $lineData['purchase_order_line_id']);

                    $remaining = bcsub($poLine->quantity, $poLine->quantity_received, 4);
                    if (bccomp((string) $lineData['quantity'], $remaining, 4) > 0) {
                        throw OverReceiptException::forLine($poLine->id, $remaining, (string) $lineData['quantity']);
                    }

                    $unitCost = (string) ($lineData['unit_cost'] ?? $poLine->unit_price);

                    $grnLine = $grn->lines()->create([
                        'purchase_order_line_id' => $poLine->id,
                        'item_id' => $poLine->item_id,
                        'quantity' => $lineData['quantity'],
                        'unit_cost' => $unitCost,
                    ]);

                    // SCHEDULE ALLOCATION (owner-confirmed): each arrival is
                    // booked against the PO line's item/due-date windows —
                    // explicit rows when the receiver adjusted the preview,
                    // else oldest-due-first by default. A line on a PO with
                    // no schedules skips this entirely (ERP-native orders
                    // keep working unchanged).
                    $this->allocateSchedules(
                        $poLine,
                        $grnLine,
                        (string) $lineData['quantity'],
                        $lineData['schedule_allocations'] ?? null,
                    );

                    // This is still the one and only inventory receipt. The
                    // material lots below add physical bag identity; they do
                    // not create another stock movement and never post Tally.
                    $movement = $this->stock->recordReceipt(
                        itemId: $poLine->item_id,
                        warehouseId: $data['warehouse_id'],
                        quantity: (string) $lineData['quantity'],
                        unitCost: $unitCost,
                        reference: $data['reference'] ?? "GRN for PO #{$order->id}",
                        movementDate: $data['received_date'] ?? null,
                        notes: $data['notes'] ?? null,
                        createdBy: $createdBy,
                        purpose: StockMovementPurpose::Receipt,
                    );

                    // NAME the ledger row instead of hunting for it later.
                    // The reference the movement carries is not an identity:
                    // two arrivals on one order with no reference of their
                    // own share the same fallback string, and the trace used
                    // to show each other's movements. The id is written here,
                    // at the one place that knows it (Phase 6, P6-02).
                    $grnLine->update(['stock_movement_id' => $movement->id]);

                    foreach ($lineData['lots'] ?? [] as $lotData) {
                        $this->traceability->createLot([
                            ...$lotData,
                            'grn_id' => $grn->id,
                            'goods_receipt_note_line_id' => $grnLine->id,
                            'item_id' => $poLine->item_id,
                            // The rate this receipt line actually used, carried
                            // onto the lot so nobody has to re-derive it later
                            // through a nullable join. PROVISIONAL by nature —
                            // the purchase invoice and landed costs arrive
                            // afterwards and are appended as cost versions;
                            // this original number is never rewritten.
                            'receipt_rate_per_kg' => $unitCost,
                            // Always 'grn': the number is physically the GRN
                            // line's unit_cost, whether the receipt named it
                            // or defaulted it from the order. Labelling the
                            // defaulted case 'po' was tempting and wrong — the
                            // backfill cannot tell those apart for historical
                            // rows, so the column would have meant one thing
                            // before this migration and another after it.
                            // 'po' stays reserved for what it says: a rate
                            // that had to be reached from the purchase order
                            // because no GRN line rate survived. The
                            // entered-vs-defaulted nuance is not lost — it is
                            // recorded in the receipt version's note below,
                            // where an auditor reads it per lot anyway.
                            'rate_source' => 'grn',
                            'receipt_rate_note' => isset($lineData['unit_cost'])
                                ? 'Goods receipt rate as entered on the receipt line.'
                                : 'Goods receipt rate defaulted from the purchase order line price.',
                            // material_lots.received_date is a date column
                            // (FIFO index + whereDate queries), so a receipt
                            // datetime is narrowed to its calendar day here
                            // rather than left to the database to truncate.
                            'received_date' => isset($data['received_date'])
                                ? Carbon::parse($data['received_date'])->toDateString()
                                : now()->toDateString(),
                            'warehouse_id' => $data['warehouse_id'],
                        ], $createdBy);
                    }

                    $poLine->increment('quantity_received', $lineData['quantity']);
                }

                $this->recomputeOrderStatus($order->fresh('lines'));

                // Announce the receipt only after the line and all its physical
                // bags exist. A replay returns above and emits nothing.
                event(new GoodsReceiptNoteReceived($grn));

                return $this->loadReceipt($grn);
            });
        } catch (QueryException $exception) {
            // Concurrent retries may both miss the first read. The unique
            // receipt_key makes one transaction win and rolls the other back
            // before it can duplicate stock. Return the winner.
            if ($receiptKey !== null) {
                $existing = GoodsReceiptNote::query()->where('receipt_key', $receiptKey)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $payloadHash);
                }
            }

            throw $exception;
        }
    }

    /**
     * Validate and enrich the physical bag manifest before the first receipt
     * row or stock movement is written. Any error therefore rolls the whole
     * GRN back, rather than leaving stock with no printable bags.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Book one GRN line's quantity against its PO line's delivery schedules.
     *
     * Explicit allocations (the receiver edited the preview) are validated:
     * every schedule must belong to this line, no allocation may exceed the
     * schedule's remaining quantity, and the sum must equal the line. The
     * default is the owner-confirmed rule — oldest due date first — walking
     * the windows in due-date order and filling each before the next. A
     * remainder beyond every schedule is allowed (the delivery genuinely
     * over-covers the plan) and simply carries no allocation row: the PO
     * line's own quantity_received remains the authoritative total.
     */
    private function allocateSchedules($poLine, $grnLine, string $quantity, ?array $explicit): void
    {
        $schedules = $poLine->schedules()->get();

        if ($schedules->isEmpty()) {
            return;
        }

        if ($explicit !== null) {
            $total = '0.0000';
            foreach ($explicit as $row) {
                $schedule = $schedules->firstWhere('id', (int) $row['purchase_order_schedule_id']);
                if ($schedule === null) {
                    throw new InvalidStatusTransitionException(
                        'a schedule allocation names a delivery window that does not belong to this order line',
                    );
                }
                $qty = bcadd((string) $row['quantity'], '0', 4);
                if (bccomp($qty, $schedule->remaining(), 4) > 0) {
                    throw new InvalidStatusTransitionException(
                        "an allocation of {$qty} kg exceeds the {$schedule->remaining()} still open on the {$schedule->due_date->toDateString()} window",
                    );
                }
                $total = bcadd($total, $qty, 4);
                $grnLine->scheduleAllocations()->create([
                    'purchase_order_schedule_id' => $schedule->id,
                    'quantity' => $qty,
                ]);
                $schedule->update(['quantity_received' => bcadd((string) $schedule->quantity_received, $qty, 4)]);
            }
            if (bccomp($total, $quantity, 4) !== 0) {
                throw new InvalidStatusTransitionException(
                    "schedule allocations total {$total} but the arrival line is {$quantity} — they must match exactly",
                );
            }

            return;
        }

        $left = $quantity;
        foreach ($schedules as $schedule) {
            if (bccomp($left, '0', 4) !== 1) {
                break;
            }
            $open = $schedule->remaining();
            if (bccomp($open, '0', 4) !== 1) {
                continue;
            }
            $take = bccomp($left, $open, 4) === 1 ? $open : $left;
            $grnLine->scheduleAllocations()->create([
                'purchase_order_schedule_id' => $schedule->id,
                'quantity' => $take,
            ]);
            $schedule->update(['quantity_received' => bcadd((string) $schedule->quantity_received, $take, 4)]);
            $left = bcsub($left, $take, 4);
        }
    }

    private function prepareLotManifests(PurchaseOrder $order, array $data): array
    {
        /** @var array<string, string> $barcodePaths */
        $barcodePaths = [];

        foreach ($data['lines'] as $lineIndex => &$lineData) {
            if (! config('production.traceability_enabled', true)
                && array_key_exists('lots', $lineData)
                && $lineData['lots'] !== []) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.lots" => 'Lot and bag traceability is disabled for this deployment.',
                ]);
            }

            $poLine = $order->lines->firstWhere('id', $lineData['purchase_order_line_id']);
            if ($poLine === null) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.purchase_order_line_id" => 'This line does not belong to the selected purchase order.',
                ]);
            }

            if (! array_key_exists('lots', $lineData)) {
                continue;
            }

            if (! Item::isKgUom($poLine->item?->uom)) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.lots" => 'Bag lots are only supported for items measured in kg.',
                ]);
            }

            $lineLotTotal = '0.0000';

            foreach ($lineData['lots'] as $lotIndex => &$lotData) {
                $path = "lines.{$lineIndex}.lots.{$lotIndex}";
                $bagCount = (int) ($lotData['bag_count'] ?? 0);

                if ($bagCount < 1) {
                    throw ValidationException::withMessages([
                        "{$path}.bag_count" => 'Each material lot must contain at least one bag.',
                    ]);
                }

                $barcodes = array_values($lotData['barcodes'] ?? []);
                if ($barcodes !== [] && count($barcodes) !== $bagCount) {
                    throw ValidationException::withMessages([
                        "{$path}.barcodes" => "Provide exactly {$bagCount} barcodes, one for each bag.",
                    ]);
                }

                foreach ($barcodes as $barcodeIndex => $barcode) {
                    if (isset($barcodePaths[$barcode])) {
                        throw ValidationException::withMessages([
                            "{$path}.barcodes.{$barcodeIndex}" => 'This barcode is repeated in the receipt.',
                        ]);
                    }

                    $barcodePaths[$barcode] = "{$path}.barcodes.{$barcodeIndex}";
                }

                $bagWeights = array_values($lotData['bag_weights'] ?? []);
                if ($bagWeights !== [] && count($bagWeights) !== $bagCount) {
                    throw ValidationException::withMessages([
                        "{$path}.bag_weights" => "Provide exactly {$bagCount} bag weights.",
                    ]);
                }

                if ($bagWeights !== []) {
                    $bagTotal = '0.0000';
                    foreach ($bagWeights as $weight) {
                        $bagTotal = bcadd($bagTotal, (string) $weight, 4);
                    }
                } elseif (isset($lotData['bag_weight_kg'])) {
                    $bagTotal = bcmul((string) $lotData['bag_weight_kg'], (string) $bagCount, 4);
                } else {
                    throw ValidationException::withMessages([
                        "{$path}.bag_weight_kg" => 'Enter a nominal bag weight or provide every individual bag weight.',
                    ]);
                }

                if (isset($lotData['total_received_kg'])
                    && bccomp((string) $lotData['total_received_kg'], $bagTotal, 4) !== 0) {
                    throw ValidationException::withMessages([
                        "{$path}.total_received_kg" => "Lot total must equal the bag total of {$bagTotal} kg.",
                    ]);
                }

                $lotData['total_received_kg'] = $bagTotal;
                $lineLotTotal = bcadd($lineLotTotal, $bagTotal, 4);
            }
            unset($lotData);

            if (bccomp($lineLotTotal, (string) $lineData['quantity'], 4) !== 0) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.lots" => "Lot totals ({$lineLotTotal} kg) must equal the received line quantity ("
                        .bcadd((string) $lineData['quantity'], '0', 4).' kg).',
                ]);
            }
        }
        unset($lineData);

        if ($barcodePaths !== []) {
            $existing = MaterialBag::query()
                ->whereIn('barcode', array_keys($barcodePaths))
                ->pluck('barcode')
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    $barcodePaths[$existing] => 'This barcode is already assigned to another material bag.',
                ]);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function replay(GoodsReceiptNote $receipt, ?string $payloadHash): GoodsReceiptNote
    {
        if ($receipt->receipt_payload_hash !== $payloadHash) {
            throw ValidationException::withMessages([
                'receipt_key' => 'This receipt key was already used for different receipt data. Generate a new key.',
            ]);
        }

        return $this->loadReceipt($receipt);
    }

    private function loadReceipt(GoodsReceiptNote $receipt): GoodsReceiptNote
    {
        $receipt->load([
            'lines.item',
            'lines.incomingInspections',
            'lines.materialLots.item',
            'lines.materialLots.bags',
            'lines.materialLots.costVersions',
            'materialLots.item',
            'materialLots.bags',
            'materialLots.costVersions',
            'warehouse',
            'purchaseOrder',
        ]);
        // The store response and a replay carry the link too: for a fresh
        // receipt the Receipt Note listener has already run (the event is
        // in-transaction), so the row exists by the time this loads.
        $this->trace->decorateReceipts([$receipt]);

        return $receipt;
    }

    private function recomputeOrderStatus(PurchaseOrder $order): void
    {
        $fullyReceived = $order->lines->every(
            fn ($line) => bccomp($line->quantity_received, $line->quantity, 4) >= 0
        );

        if ($fullyReceived) {
            $order->update(['status' => PurchaseOrderStatus::Closed]);

            return;
        }

        $anyReceived = $order->lines->contains(
            fn ($line) => bccomp($line->quantity_received, '0', 4) > 0
        );

        if ($anyReceived) {
            $order->update(['status' => PurchaseOrderStatus::PartiallyReceived]);
        }
    }

    /**
     * The states goods_receipt_notes.tally_staging may carry —
     * PurchaseOrderService::STAGING_STATES minus 'dismissed': a receipt has
     * no cancel/close lifecycle to withdraw a staged voucher for.
     */
    public const STAGING_STATES = ['disabled', 'refused', 'enqueued'];

    /**
     * The ONLY writer of goods_receipt_notes.tally_staging — the mirror of
     * PurchaseOrderService::recordTallyStaging(), and called by the same
     * listener family (TallySyncEventServiceProvider): disabled (the flag
     * is off; PENDING Q63) / refused (named reasons — an unmapped item, an
     * unmapped vendor ledger, no allowed company) / enqueued (entry_id).
     * Writes this one column and nothing else — no Tally, no stock, no
     * status change.
     *
     * @param  array{state: string, reasons?: list<array{code: string, detail?: ?string}>, entry_id?: ?int, at?: ?string}  $staging
     */
    public function recordTallyStaging(GoodsReceiptNote $note, array $staging): void
    {
        $state = (string) ($staging['state'] ?? '');
        if (! in_array($state, self::STAGING_STATES, true)) {
            throw new InvalidArgumentException(
                'tally_staging.state must be one of '.implode('|', self::STAGING_STATES).", got \"{$state}\".",
            );
        }

        $reasons = [];
        foreach ($staging['reasons'] ?? [] as $reason) {
            $reasons[] = [
                'code' => (string) ($reason['code'] ?? 'unknown'),
                'detail' => isset($reason['detail']) ? (string) $reason['detail'] : null,
            ];
        }

        $record = [
            'state' => $state,
            'reasons' => $reasons,
            'at' => isset($staging['at']) ? (string) $staging['at'] : now()->toIso8601String(),
        ];
        if (isset($staging['entry_id'])) {
            $record['entry_id'] = (int) $staging['entry_id'];
        }

        // One column, nothing else on the receipt changes.
        $note->forceFill(['tally_staging' => $record])->save();
    }
}
