<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Exceptions\BagOverloadException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The FACTORY DAY BIN — the one central place raw material is moved to when
 * it comes out of the store, before any machine runs.
 *
 * Deliberately NOT a new ledger. The day bin is simply a WAREHOUSE:
 *
 *  - loading it is the existing store → warehouse stock transfer
 *    (POST /inventory/stock-movements/transfers). Material changes location,
 *    nothing is consumed, no Tally voucher is posted.
 *  - its balance is the ordinary stock_balances row for (item, day-bin
 *    warehouse) — no second set of balance maths to reconcile, and it still
 *    maps to a Tally godown like any other warehouse.
 *  - consumption reduces it automatically, because every material line at
 *    batch completion already carries its OWN warehouse_id: a line issued
 *    from the day-bin warehouse decrements that warehouse's balance through
 *    the same StockMovementService::recordIssue every other issue uses.
 *
 * This class only answers "which warehouse is the day bin" and "what is in
 * it", so the per-machine day_bin_movements ledger and the barcode bin-bay
 * (the optional bag-level detail) stay exactly as they are.
 *
 * NOT CONFIGURED IS A NORMAL STATE. Until someone names the warehouse,
 * warehouseId() is null and every caller must behave exactly as it did
 * before this feature existed — never a blocked Start or Complete.
 */
class FactoryDayBinService
{
    /**
     * app_settings key. Stored as the warehouse id (int) rather than a name,
     * so renaming the godown in Tally cannot silently unconfigure the bin.
     */
    public const SETTING_KEY = 'production_day_bin_warehouse_id';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * The configured day-bin warehouse id, or null when unset. Returns null
     * for a warehouse that has since been deleted: a dangling id must read
     * as "not configured" (the degrade path) rather than as a live location
     * nothing can be issued from.
     */
    public function warehouseId(): ?int
    {
        return $this->warehouse()?->id;
    }

    public function warehouse(): ?Warehouse
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        if (! is_numeric($stored)) {
            return null;
        }

        return $this->warehouses->find((int) $stored);
    }

    /** Name the day-bin warehouse (null clears it, back to today's behaviour). */
    public function setWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_KEY, $warehouseId);
    }

    /**
     * The CENTRALIZED bag-scan load: one barcode scan on the Shift Floor
     * moves a bag's kg out of the store warehouse into the factory day-bin
     * warehouse — for all machines at once, so no work center is picked and
     * no per-machine day_bin_movements ledger row is written. The stock
     * transfer IS the record (no new ledger, exactly like the manual
     * transfer form this replaces for the scan case).
     *
     * Bag state is Inventory's; the status/remaining handling here mirrors
     * TraceabilityService::loadBagToDayBin line for line (full load empties
     * the bag → Consumed, partial load leaves it InStore), and the bag
     * decrement + warehouse transfer share ONE transaction so they can
     * never drift. day_bin_work_center_id stays untouched — the central
     * bin is not a machine.
     *
     * $recordedBy is the AUTHENTICATED user (the audit identity on the
     * stock movements); $supervisorId is only a note of who was acting
     * supervisor at the scan, never the identity.
     *
     * @return array{bag: MaterialBag, balance: StockBalance}
     */
    public function loadBag(string $barcode, ?string $quantityKg, int $recordedBy, ?int $supervisorId = null): array
    {
        $warehouse = $this->warehouse();
        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'day_bin' => 'No day-bin warehouse is configured — name it in Production settings before loading bags.',
            ]);
        }

        $barcode = trim($barcode);
        if ($barcode === '') {
            throw ValidationException::withMessages([
                'barcode' => 'Scan or type a bag barcode.',
            ]);
        }

        return DB::transaction(function () use ($barcode, $quantityKg, $recordedBy, $supervisorId, $warehouse) {
            $bag = MaterialBag::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if ($bag === null) {
                throw ValidationException::withMessages([
                    'barcode' => 'Unknown bag barcode — no registered bag carries this code.',
                ]);
            }

            if ($bag->status === MaterialBagStatus::Consumed || bccomp((string) $bag->remaining_kg, '0', 4) !== 1) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} is already consumed — nothing is left in it to load.",
                ]);
            }

            if ($bag->current_warehouse_id === null) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} has no store warehouse recorded, so there is nowhere to move its stock from — register the lot with its warehouse first.",
                ]);
            }

            if ($bag->current_warehouse_id === $warehouse->id) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} already sits in the day-bin warehouse — there is nothing to move.",
                ]);
            }

            $remaining = bcadd((string) $bag->remaining_kg, '0', 4);
            $quantity = $quantityKg !== null ? bcadd($quantityKg, '0', 4) : $remaining;

            if (bccomp($quantity, '0', 4) !== 1 || bccomp($quantity, $remaining, 4) === 1) {
                throw BagOverloadException::make($bag->barcode, $quantity, $remaining);
            }

            // Same rule as TraceabilityService::loadBagToDayBin: a load that
            // drives remaining_kg to 0 leaves the bag Consumed (it holds
            // nothing any more); a partial load pours off the weighed kg and
            // the bag stays InStore.
            $fullLoad = bccomp($quantity, $remaining, 4) === 0;
            $bag->remaining_kg = bcsub($remaining, $quantity, 4);
            if ($fullLoad) {
                $bag->status = MaterialBagStatus::Consumed;
            }
            $bag->save();

            $lot = $bag->lot()->first();

            $notes = null;
            if ($supervisorId !== null) {
                $supervisor = User::query()->find($supervisorId);
                $notes = 'Acting supervisor: '.($supervisor?->name ?? "user #{$supervisorId}");
            }

            $this->stock->recordTransfer(
                itemId: $lot->item_id,
                fromWarehouseId: $bag->current_warehouse_id,
                toWarehouseId: $warehouse->id,
                quantity: $quantity,
                reference: "Day bin load — bag {$bag->barcode}",
                notes: $notes,
                createdBy: $recordedBy,
            );

            $balance = StockBalance::query()
                ->with('item')
                ->where('item_id', $lot->item_id)
                ->where('warehouse_id', $warehouse->id)
                ->firstOrFail();

            return [
                'bag' => $bag->load('lot.item'),
                'balance' => $balance,
            ];
        });
    }

    /**
     * What the factory day bin holds right now — always visible without
     * picking a machine, which is the whole point of the central bin.
     *
     * `materials` is empty (not an error) when nothing is in the bin, and the
     * whole read answers `warehouse: null` when no bin is configured yet, so
     * the screen can prompt instead of failing.
     *
     * @return array{warehouse: ?Warehouse, materials: Collection<int, StockBalance>}
     */
    public function snapshot(): array
    {
        $warehouse = $this->warehouse();

        return [
            'warehouse' => $warehouse,
            'materials' => $warehouse === null
                ? collect()
                // Zero-balance rows are kept: "resin 0 kg" is the answer a
                // supervisor needs before starting, and hiding the line reads
                // as "material we don't track here".
                : $this->stock->balancesForWarehouse($warehouse->id),
        ];
    }
}
