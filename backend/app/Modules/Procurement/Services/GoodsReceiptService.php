<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Exceptions\OverReceiptException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a GRN is the one place Procurement actually moves stock. It never
 * touches Inventory's tables directly — it goes through StockMovementService,
 * the same as any other caller of that module, so Inventory's valuation and
 * balance-locking logic stays in one place.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly TraceabilityService $traceability,
    ) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return GoodsReceiptNote::query()
            ->with([
                'lines.item',
                'lines.materialLots.item',
                'lines.materialLots.bags',
                'materialLots.item',
                'materialLots.bags',
                'warehouse',
                'purchaseOrder.vendor',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);
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
                ]);

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

                    // This is still the one and only inventory receipt. The
                    // material lots below add physical bag identity; they do
                    // not create another stock movement and never post Tally.
                    $this->stock->recordReceipt(
                        itemId: $poLine->item_id,
                        warehouseId: $data['warehouse_id'],
                        quantity: (string) $lineData['quantity'],
                        unitCost: $unitCost,
                        reference: $data['reference'] ?? "GRN for PO #{$order->id}",
                        movementDate: $data['received_date'] ?? null,
                        notes: $data['notes'] ?? null,
                        createdBy: $createdBy,
                    );

                    foreach ($lineData['lots'] ?? [] as $lotData) {
                        $this->traceability->createLot([
                            ...$lotData,
                            'grn_id' => $grn->id,
                            'goods_receipt_note_line_id' => $grnLine->id,
                            'item_id' => $poLine->item_id,
                            'received_date' => $data['received_date'] ?? now()->toDateString(),
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

            if (! $this->isMassUom($poLine->item?->uom)) {
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
        return $receipt->load([
            'lines.item',
            'lines.materialLots.item',
            'lines.materialLots.bags',
            'materialLots.item',
            'materialLots.bags',
            'warehouse',
            'purchaseOrder',
        ]);
    }

    private function isMassUom(?string $uom): bool
    {
        return in_array(
            rtrim(strtolower(trim((string) $uom)), '.'),
            ['kg', 'kgs', 'kilogram', 'kilograms'],
            true,
        );
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
}
