<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * WHICH ROW IS "PRODUCTION/WIP" — the location that holds material issued to
 * production and not yet consumed (DEC-20260817-001).
 *
 * The owner named three logical locations: Raw Material Store →
 * Production/WIP → Finished Goods Store. There is no Day Bin. Production/WIP
 * is what makes "issued to production" a real stock state rather than a flag
 * on a row, and the decision is explicit that a WIP warehouse already exists
 * and must be REUSED — a synonym must never be minted beside it.
 *
 * PRECEDENCE:
 *   1. the app setting, when it names a warehouse that still exists;
 *   2. else the single warehouse whose code is exactly 'WIP';
 *   3. else null → the caller refuses with a plain 422 naming the fix.
 *
 * `is_active` IS DELIBERATELY NOT FILTERED, and that is the one place this
 * class parts company with FactoryWarehouseResolver. The same reasoning
 * FactoryWarehouseResolver::dayBin() writes down applies with more force
 * here: the live WIP row was deactivated by the 01-Aug "the factory is one
 * place" migration, and if an inactive row read as "not configured" while
 * material was already standing in it, the stock would be stranded — issued
 * into a location nothing can be drawn out of, with the ledger invariant
 * green the whole time and the floor unable to complete a batch. Where the
 * material actually IS beats whether the row is still selectable.
 *
 * NOTHING HERE CREATES OR REACTIVATES A ROW. Warehouse master data is
 * changed by a person through the warehouse endpoints (and on live, through
 * the manual master-data workflow, dry-run first) — never as a side effect
 * of a migration or of an issue being recorded.
 */
class ProductionWipLocationResolver
{
    /** app_settings key. The id, not the name — renaming a godown must not unconfigure the factory. */
    public const SETTING_KEY = 'production_wip_warehouse_id';

    /** The code of the row DEC-20260817-001 says already exists. */
    public const CANONICAL_CODE = 'WIP';

    public const UNRESOLVED_MESSAGE = 'No Production/WIP location could be worked out, so there is nowhere to '
        .'issue this material TO. Name it in Inventory settings (the warehouse holding material issued to '
        .'production but not yet consumed) — nothing is set and no warehouse carries the code "WIP".';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
        private readonly TallyGodownResolver $godowns,
    ) {}

    public function warehouse(): ?Warehouse
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        if (is_numeric($stored)) {
            $named = $this->warehouses->find((int) $stored);

            if ($named !== null) {
                return $named;
            }
        }

        // limit(2): a second row carrying the canonical code is an
        // ambiguity, not a tie to break — it declines rather than picking.
        $byCode = Warehouse::query()->where('code', self::CANONICAL_CODE)->limit(2)->get();

        return $byCode->count() === 1 ? $byCode->first() : null;
    }

    public function warehouseId(): ?int
    {
        return $this->warehouse()?->id;
    }

    /** Name the Production/WIP location (null clears it, back to the code lookup). */
    public function setWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_KEY, $warehouseId);
    }

    /**
     * The location, or a 422 naming the fix — and it checks the SECOND thing
     * too: that a batch consuming out of Production/WIP will still name a
     * godown Tally knows.
     *
     * Why that check lives at the issue and not at the voucher: this phase
     * moves the consumption source to Production/WIP, and Tally has never
     * heard of it. TallyGodownResolver already aliases an internal location
     * to the godown it sits under (its own tally_guid, else the nearest
     * parent's, else the sole Tally-linked godown), which is exactly this
     * factory's one-godown shape — so on live the name a NEW voucher carries
     * does not change. Where the alias is genuinely ambiguous the resolver
     * correctly refuses to guess, and the honest moment to say so is BEFORE
     * the material is handed over, not hours later when a finished batch
     * turns out to be unpostable.
     *
     * @throws ValidationException 422 naming the fix
     */
    public function warehouseOrFail(): Warehouse
    {
        $warehouse = $this->warehouse();

        if ($warehouse === null) {
            throw ValidationException::withMessages(['production_wip' => self::UNRESOLVED_MESSAGE]);
        }

        // ONLY WHERE THERE IS A GODOWN TO PRESERVE. A system with no
        // Tally-linked warehouse at all has no godown name for a new voucher
        // to change, and the voucher preview/readiness gate already flags
        // every line in that state — refusing the factory's material flow on
        // top of that would stop the store recording real handovers over a
        // Tally setting. Where Tally identity DOES exist and Production/WIP
        // aliases to none of it, the refusal is the point.
        if ($this->godowns->resolve($warehouse) === null && Warehouse::query()->whereNotNull('tally_guid')->exists()) {
            throw ValidationException::withMessages([
                'production_wip' => sprintf(
                    'Production/WIP ("%s") aliases to no godown Tally knows, so a batch consuming from it could '
                    .'not be posted. Link it to the company godown (set its parent warehouse) before issuing '
                    .'material to production.',
                    $warehouse->name,
                ),
            ]);
        }

        return $warehouse;
    }
}
