<?php

namespace App\Modules\Production\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Validation\ValidationException;

/**
 * WHICH WAREHOUSE, ANSWERED BY THE SERVER — never by the person on the floor.
 *
 * The owner's ruling (30-Jul): "there is no need to select any store in any
 * place. what is packing store — everything happening inside the factory."
 * One factory, one physical place. A supervisor mid-shift must never be asked
 * where finished bottles go or where resin came from; they are standing in
 * the only place either could be. Every warehouse a payload needs is therefore
 * resolved HERE, from configuration and from facts already in the database.
 *
 * It still has to reconcile with the accountant's books, and that is what
 * makes a silent default safe rather than a guess: this factory's Tally has
 * EXACTLY ONE godown ("SWAASHPET POLYMERS PVT LTD"), so when nothing has been
 * configured there is genuinely nothing to choose between — the one
 * Tally-linked warehouse is the factory. This is the same reasoning
 * TallyGodownResolver already applies as its rule 3 for voucher godown names;
 * the query is repeated here rather than shared because the two answer
 * different questions and need different filters (see below), and because
 * Inventory's resolver is not this module's to change.
 *
 * PRECEDENCE, identical for every role:
 *   1. the app setting for that role, when it names a live, ACTIVE warehouse;
 *   2. else the single ACTIVE Tally-linked warehouse, when there is exactly
 *      one (this factory's reality);
 *   3. else null.
 *
 * `is_active` is filtered at every step, and that is the deliberate
 * difference from TallyGodownResolver: that class answers "what godown name
 * does this line post under" and must still answer for a retired warehouse;
 * this class answers "which warehouse do we PICK", and a retired warehouse
 * must never be picked. It is also what keeps rehearsal/demo residue
 * (RM-STORE, WIP, FG-STORE) out of the answer once it is deactivated — the
 * rows stay for their history, they just stop being selectable.
 *
 * NULL IS NEVER PAPERED OVER. When a role cannot be resolved the caller
 * raises a plain 422 naming the Settings fix (see the *OrFail methods). A
 * wrong-but-silent pick would book finished goods into the resin bin or
 * issue material from a warehouse that never held it, and both surface as
 * a Tally rejection hours after the shift is over.
 */
class FactoryWarehouseResolver
{
    /**
     * app_settings keys. Warehouse ids, not names — renaming the godown in
     * Tally must not silently unconfigure the factory (same reasoning as
     * FactoryDayBinService::SETTING_KEY, which this class reads for the
     * day-bin role rather than redefining).
     */
    public const SETTING_FINISHED_GOODS = 'production_finished_goods_warehouse_id';

    public const SETTING_RAW_MATERIAL = 'production_raw_material_warehouse_id';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
        private readonly FactoryDayBinService $dayBinService,
    ) {}

    /**
     * Where finished goods land. This is a batch's OUTPUT location — the
     * warehouse the completion's stock receipt and its average-cost lookup
     * both use — so it must never resolve to the raw-material day bin.
     */
    public function finishedGoods(): ?Warehouse
    {
        return $this->fromSetting(self::SETTING_FINISHED_GOODS) ?? $this->soleTallyLinkedWarehouse();
    }

    /** Where raw material is issued from when no bin holds it. */
    public function rawMaterial(): ?Warehouse
    {
        return $this->fromSetting(self::SETTING_RAW_MATERIAL) ?? $this->soleTallyLinkedWarehouse();
    }

    /**
     * The factory day bin, under the same precedence as the other roles.
     *
     * DELEGATES to FactoryDayBinService rather than re-reading the setting,
     * and that is the one deliberate exception to the is_active filter above.
     * The two classes must never disagree about which warehouse IS the bin:
     * FactoryDayBinService::warehouse() is what bag loading transfers stock
     * INTO, and this method is what consumption is later issued OUT OF. If a
     * deactivated-but-configured bin read as null here while loading still
     * used it, material would sit in one warehouse and be issued from
     * another — the exact silent stock corruption this class exists to
     * prevent. Where the material actually is beats whether the row is still
     * selectable, so "issue from where the bags went" wins.
     *
     * The sole-Tally fallback is added only on top of that, and only because
     * declining to answer HERE means asking a supervisor. It is NOT pushed
     * back into FactoryDayBinService: there, null legitimately means "no bin
     * has been named" and the floor screens correctly degrade to pre-day-bin
     * behaviour rather than transferring bags into a warehouse nobody chose.
     */
    public function dayBin(): ?Warehouse
    {
        return $this->dayBinService->warehouse() ?? $this->soleTallyLinkedWarehouse();
    }

    /**
     * Where THIS material was issued from — the one item-aware answer here,
     * and the reason it has to be item-aware:
     *
     * kg raw material (resin, masterbatch) is moved into the day bin before
     * a machine runs, so its balance sits in the bin and an issue booked
     * against any other warehouse fails on stock it does not have. Packing
     * material counted in Nos (film, tape, cartons) never passes through the
     * kg bin at all, so the same default would fail it for the opposite
     * reason. The stock balance already knows which is which, so it decides
     * — a fact in the database, never an item name or a person's answer.
     *
     * A bin holding SOME but not enough of the material still wins: that is a
     * real shortage the issue should report against the bin, not a signal to
     * quietly issue from somewhere the material never was.
     */
    public function consumptionSource(int $itemId): ?Warehouse
    {
        $bin = $this->dayBin();

        if ($bin !== null && $this->holdsStock($bin, $itemId)) {
            return $bin;
        }

        return $this->rawMaterial();
    }

    /**
     * @throws ValidationException 422 naming the Settings fix
     */
    public function finishedGoodsOrFail(string $field = 'warehouse_id'): Warehouse
    {
        $warehouse = $this->finishedGoods();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                $field => 'No finished-goods warehouse could be worked out for this factory. '
                    .'Name one in Production settings (finished-goods warehouse) — nothing is set, '
                    .'and there is no single Tally-linked warehouse to fall back on.',
            ]);
        }

        return $warehouse;
    }

    /**
     * @throws ValidationException 422 naming the Settings fix
     */
    public function consumptionSourceOrFail(int $itemId, string $field): Warehouse
    {
        $warehouse = $this->consumptionSource($itemId);

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                $field => 'No warehouse could be worked out to issue this material from. '
                    .'Name the raw-material warehouse in Production settings — nothing is set, '
                    .'and there is no single Tally-linked warehouse to fall back on.',
            ]);
        }

        return $warehouse;
    }

    /** Name the warehouse for a role (null clears it, back to the fallback). */
    public function setFinishedGoodsWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_FINISHED_GOODS, $warehouseId);
    }

    public function setRawMaterialWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_RAW_MATERIAL, $warehouseId);
    }

    /** What is stored for a role, before any fallback — what Settings shows. */
    public function configuredFinishedGoodsWarehouseId(): ?int
    {
        return $this->fromSetting(self::SETTING_FINISHED_GOODS)?->id;
    }

    public function configuredRawMaterialWarehouseId(): ?int
    {
        return $this->fromSetting(self::SETTING_RAW_MATERIAL)?->id;
    }

    /**
     * A stored id reads as "not set" when it names a warehouse that has since
     * been deleted OR deactivated — the same degrade path FactoryDayBinService
     * takes for a dangling id, extended to retirement, so retiring a warehouse
     * cannot leave every payload pointing at a location nothing can move
     * through.
     */
    private function fromSetting(string $key): ?Warehouse
    {
        $stored = $this->settings->get($key);

        if (! is_numeric($stored)) {
            return null;
        }

        $warehouse = $this->warehouses->find((int) $stored);

        return $warehouse !== null && $warehouse->is_active ? $warehouse : null;
    }

    /**
     * The single ACTIVE Tally-linked warehouse, or null when there is not
     * exactly one. Zero and two-or-more both decline: a system with a real
     * choice to make must not have it made for it, and declining surfaces as
     * a plain 422 rather than as stock in the wrong place.
     *
     * limit(2): only "exactly one" ever matters, never the full list.
     */
    private function soleTallyLinkedWarehouse(): ?Warehouse
    {
        $linked = Warehouse::query()
            ->whereNotNull('tally_guid')
            ->where('is_active', true)
            ->limit(2)
            ->get();

        return $linked->count() === 1 ? $linked->first() : null;
    }

    private function holdsStock(Warehouse $warehouse, int $itemId): bool
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('item_id', $itemId)
            ->where('quantity', '>', 0)
            ->exists();
    }
}
