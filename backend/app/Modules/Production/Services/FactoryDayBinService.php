<?php

namespace App\Modules\Production\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Support\Collection;

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
