<?php

namespace App\Modules\Production\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StoreIssueService;
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
 * PRECEDENCE, for the finished-goods, raw-material and day-bin roles:
 *   1. the app setting for that role, when it names a live, ACTIVE warehouse;
 *   2. else the single ACTIVE Tally-linked warehouse, when there is exactly
 *      one (this factory's reality);
 *   3. else null.
 *
 * The PACKING MATERIAL role deliberately stops at step 1 — see
 * packingMaterial() for why a fallback that is safe for the other roles is a
 * confidently wrong answer for that one.
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

    public const SETTING_PACKING_MATERIAL = 'production_packing_material_warehouse_id';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
        private readonly FactoryDayBinService $dayBinService,
        // WHERE MATERIAL ISSUED TO PRODUCTION IS STANDING
        // (DEC-20260817-001). Read, never written, from here: this
        // class only needs to know which row it is, and the resolver
        // deliberately does not filter is_active for it — see its own
        // docblock for why a deactivated row must still be findable.
        private readonly ProductionWipLocationResolver $productionWip,
        // Whether the STORE actually issued this material into Production/WIP
        // — read through Inventory's own service, never its tables.
        private readonly StoreIssueService $storeIssues,
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
     * THE PACKING MATERIAL STORE — cartons, trays, film pouches, tape.
     *
     * The owner's voucher rule (31-Jul): "Raw materials from the agreed RM or
     * machine-WIP location, packing materials from the Packing Material
     * Store, finished goods into the FG Store."
     *
     * AND IT IS THE ONE ROLE WITH NO FALLBACK — deliberately, breaking the
     * "identical precedence for every role" pattern the class docblock
     * states, so read this before adding one back.
     *
     * The sole-Tally-linked fallback is safe for the other two roles because
     * a factory with one godown genuinely has nothing to choose between: the
     * resin and the bottles are both in the one place Tally knows. Packing
     * material is the case where that stops being true — a Packing Material
     * Store is a SECOND named location, and the whole reason the owner named
     * it separately is that cartons do not come out of the resin store.
     * Falling back would therefore not be "the only possible answer", it
     * would be a confident wrong one: tape and trays issued out of the raw
     * material godown, reconciling in nobody's books.
     *
     * So an unresolved packing store answers null, and the voucher preview
     * NAMES it rather than posting somewhere plausible. That is also why
     * there is no packingMaterialOrFail() twin: nothing in the production
     * path may refuse a shift over this. The shift is real and gets recorded;
     * it is the POSTING that waits for the setting.
     */
    public function packingMaterial(): ?Warehouse
    {
        return $this->fromSetting(self::SETTING_PACKING_MATERIAL);
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
        // PRODUCTION/WIP FIRST (Phase 7.5, DEC-20260817-001). Once the store
        // has ISSUED this material to production, the kilograms are standing
        // in Production/WIP and that is where the batch consumes them from —
        // anywhere else would issue stock out of a location that no longer
        // holds it and strand the issued material where nothing can draw it.
        //
        // TWO CONDITIONS, NOT ONE. A STORE ISSUE has to have put material
        // there: the WIP row is OLDER THAN THIS PHASE and already carries
        // balances from the rehearsal data, so "WIP holds some" alone would
        // fire on material no store issue ever put there and quietly
        // redirect the first completion after deploy.
        //
        // THE OTHER CONDITION IS "STILL IN PLAY", NOT "HOLDS A POSITIVE
        // BALANCE", and the difference closes a hole this phase would
        // otherwise leave open. A positive-balance test stops being true the
        // moment a batch consumes everything standing — so the NEXT batch
        // would drop through to the store and draw material the store never
        // issued: the store's balance falls, NO shortfall is recorded
        // anywhere, the issue still says the kilograms are out on the floor,
        // and the over-consumption becomes invisible. Production/WIP
        // therefore stays the source while EITHER its balance is non-zero
        // (including negative — a location already over-drawn is not one to
        // walk away from) OR the store has an open handover of this material
        // it has not had back. The over-draw then lands on Production/WIP,
        // where it belongs, and trips the completion's own shortfall record.
        //
        // A location holding SOME but not enough still wins, exactly as the
        // day-bin branch below does: that is the real shortage the
        // completion is meant to report, not a signal to issue from
        // somewhere the material never was.
        //
        // AND IT STILL LETS GO. Once the balance is flat and no issue is
        // open, nothing is standing in production and consumption is
        // answered exactly as it was before this phase existed. A factory
        // that has issued nothing sees no change at all.
        $wip = $this->productionWip->warehouse();

        if ($wip !== null
            && $this->storeIssues->hasIssuedIntoProduction($itemId, $wip->id)
            && $this->productionWipIsInPlay($wip, $itemId)
        ) {
            return $wip;
        }

        $bin = $this->dayBin();

        if ($bin !== null && $this->holdsStock($bin, $itemId)) {
            return $bin;
        }

        return $this->rawMaterial();
    }

    /**
     * Is this the Production/WIP location? Asked by the completion path so a
     * shortfall drawn out of it can say what it actually means — the store
     * has not issued this much, rather than the store's stock record being
     * behind (see ShiftProductionEntryService::completeBatch).
     */
    public function isProductionWip(?int $warehouseId): bool
    {
        if ($warehouseId === null) {
            return false;
        }

        return $this->productionWip->warehouseId() === $warehouseId;
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

    public function setPackingMaterialWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_PACKING_MATERIAL, $warehouseId);
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
     * Identical to packingMaterial()?->id, and kept as its own method only so
     * the settings read speaks the same "configured vs resolved" pair for all
     * three roles. For this role the two are the same figure by design —
     * there is no fallback for a resolved value to differ from.
     */
    public function configuredPackingMaterialWarehouseId(): ?int
    {
        return $this->fromSetting(self::SETTING_PACKING_MATERIAL)?->id;
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

    /**
     * Is Production/WIP still the place this material's consumption belongs
     * against? See the long note in consumptionSource() for why this is not
     * holdsStock().
     *
     * A NON-ZERO balance, not a positive one: an already over-drawn location
     * keeps every further over-draw where it can be seen, instead of pushing
     * it onto a store that never handed the material over.
     */
    private function productionWipIsInPlay(Warehouse $wip, int $itemId): bool
    {
        $balance = StockBalance::query()
            ->where('warehouse_id', $wip->id)
            ->where('item_id', $itemId)
            ->where('quantity', '!=', 0)
            ->exists();

        return $balance || $this->storeIssues->hasMaterialStandingInProduction($itemId, $wip->id);
    }
}
