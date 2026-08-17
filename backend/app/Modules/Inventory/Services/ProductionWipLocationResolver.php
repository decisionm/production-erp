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
 * PRECEDENCE — WIP'S OWN IDENTITY, and nothing about any other warehouse:
 *   1. the app setting, when it names a warehouse that still exists;
 *   2. else the single warehouse whose code is exactly 'WIP';
 *   3. else null → the caller refuses with a plain 422 naming the fix.
 *
 * Which godown Tally sees for it is a SEPARATE question (tallyGodown()), and
 * deliberately not one the handover is refused over — see warehouseOrFail().
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

    /**
     * The godown Tally would see for the material standing in Production/WIP
     * — WIP's own tally_guid, else the nearest Tally-linked ancestor, else
     * the sole Tally-linked warehouse (TallyGodownResolver's rules, asked
     * once so that the voucher payload, the voucher preview and the stock
     * reconcile can never disagree about it).
     *
     * Null is a real answer and means exactly one thing: the kilograms
     * standing in Production/WIP post under NO godown the accountant's books
     * know. Callers must say so rather than attach them to a godown they
     * were not proven to belong to.
     */
    public function tallyGodown(): ?Warehouse
    {
        $warehouse = $this->warehouse();

        return $warehouse === null ? null : $this->godowns->resolve($warehouse);
    }

    /** Name the Production/WIP location (null clears it, back to the code lookup). */
    public function setWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_KEY, $warehouseId);
    }

    /**
     * The location, or a 422 naming the fix. THE ONE THING IT REFUSES OVER is
     * WIP's own identity: no setting names a warehouse that still exists and
     * no single row carries the canonical code, so there is genuinely nowhere
     * to issue this material TO.
     *
     * IT DELIBERATELY DOES NOT REFUSE OVER TALLY. An earlier draft of this
     * phase also required that Production/WIP alias to a godown Tally knows,
     * on the reasoning that a batch consuming out of an unaliased location
     * would later be unpostable. The reasoning is right; the place was wrong.
     * TallyGodownResolver's alias ends in a SOLE-Tally-linked-warehouse
     * fallback, and AUDIT-WAREHOUSES-2026-08-17 §4 records that this system
     * already has TWO rows carrying a `tally_guid` — which kills that
     * fallback for every unparented internal location. The gate would
     * therefore have refused EVERY store issue on day one, over a warehouse
     * master question, while the resin was physically walking out of the
     * store either way. A gate that fires on the count of OTHER warehouses'
     * Tally identity is not a statement about Production/WIP at all.
     *
     * The unpostable-godown question is not lost — it is asked where it is
     * answerable and where it is actually enforced:
     *
     *   - `VoucherPreviewService` flags the line by name (`name_only`: no
     *     Tally identity here and it aliases to no Tally-known godown), and
     *   - the accounts-approval gate refuses the approval outright when
     *     `production.approvals.require_postable_voucher` is on.
     *
     * The store records the physical handover; the POSTING waits for the
     * master, and the fix is the ordinary one — parent the WIP row to the
     * company godown, which is master data a person changes (on live through
     * the manual workflow, dry-run first), never a side effect of an issue.
     *
     * @throws ValidationException 422 naming the fix
     */
    public function warehouseOrFail(): Warehouse
    {
        $warehouse = $this->warehouse();

        if ($warehouse === null) {
            throw ValidationException::withMessages(['production_wip' => self::UNRESOLVED_MESSAGE]);
        }

        return $warehouse;
    }
}
