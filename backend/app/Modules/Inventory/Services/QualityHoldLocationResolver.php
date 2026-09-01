<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * WHICH ROW IS "QUALITY HOLD" — where material returned from Production in a
 * DAMAGED condition waits for Quality to look at it (DEC-20260901-003).
 *
 * The owner's rule: a good return goes back to usable stock, a damaged one
 * goes to Quality inspection, and only after Quality CONFIRMS the damage does
 * it move to Scrap. A damaged return must never go directly back to usable
 * stock.
 *
 * WHY A LOCATION AND NOT A FLAG, because this is the whole guarantee. Stock
 * balances — and every stock read above them, including the four figures of
 * DEC-20260831-002 — are per ITEM AND WAREHOUSE. Material standing in a
 * location that is not the Store is therefore out of the Store's issuable
 * balance BY CONSTRUCTION: no outflow door has to remember to subtract it, no
 * screen has to filter it, and a future door written by someone who never
 * read this file cannot accidentally issue it. A boolean on a movement row
 * would have needed every one of those to be right forever.
 *
 * This is exactly the shape Production/WIP already has (DEC-20260817-001,
 * DEC-20260830-002): one physical godown, several INTERNAL locations, told
 * apart by warehouse row rather than by building. Quality hold is one more of
 * those, and this class is deliberately a near-copy of
 * ProductionWipLocationResolver rather than a generalisation of it — the two
 * answer different questions, will be configured separately, and a shared
 * abstraction over two callers would only make the next change harder.
 *
 * PRECEDENCE — quality hold's own identity, nothing about any other row:
 *   1. the app setting, when it names a warehouse that still exists;
 *   2. else the single warehouse whose code is exactly 'QC-HOLD';
 *   3. else null → the caller REFUSES with a 422 naming the fix.
 *
 * NULL REFUSES, IT NEVER FALLS BACK. Every other resolver in this system has
 * some fallback; this one must not, and the reason is the rule it implements.
 * A fallback here would put damaged material in the Store — the one outcome
 * the owner said must never happen — and it would do it silently, on the
 * happy path, in the exact situation where nobody has configured anything.
 * So an unresolved quality hold means the damaged return is refused, and the
 * refusal names the fix.
 *
 * ON LIVE THIS RESOLVES TO NULL TODAY. No warehouse row carries the code
 * QC-HOLD and no setting names one, so damaged returns refuse until a person
 * creates and names the row through the ordinary master-data route (on live,
 * the manual workflow, dry-run first). That is deliberate and it is the safe
 * direction: refusing a return is recoverable in a minute, and material
 * quietly re-issued to the floor is not.
 *
 * NOTHING HERE CREATES OR REACTIVATES A ROW — the same rule
 * ProductionWipLocationResolver states, for the same reason. Warehouse master
 * data is changed by a person, never as a side effect of a return.
 *
 * `is_active` IS NOT FILTERED, matching Production/WIP: once material is
 * standing in the row, where it actually IS beats whether the row is still
 * selectable. A hold that stopped resolving because someone deactivated the
 * row would strand the material AND re-open the door it was holding shut.
 */
class QualityHoldLocationResolver
{
    /** app_settings key. The id, not the name — renaming a godown must not unconfigure the factory. */
    public const SETTING_KEY = 'quality_hold_warehouse_id';

    /** The code looked for when no setting names a row. */
    public const CANONICAL_CODE = 'QC-HOLD';

    public const UNRESOLVED_MESSAGE = 'This return is marked damaged, and damaged material may not go back into '
        .'the store as usable stock. There is nowhere to put it: no quality-hold location is configured and no '
        .'warehouse carries the code "QC-HOLD". Name one in Inventory settings (the location holding returned '
        .'material waiting for Quality), or record this line as good if it is not damaged.';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
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

        // limit(2): a second row carrying the canonical code is an ambiguity,
        // not a tie to break — it declines rather than picking, exactly as
        // ProductionWipLocationResolver does. Picking one of two would put
        // damaged material somewhere nobody chose.
        $byCode = Warehouse::query()->where('code', self::CANONICAL_CODE)->limit(2)->get();

        return $byCode->count() === 1 ? $byCode->first() : null;
    }

    public function warehouseId(): ?int
    {
        return $this->warehouse()?->id;
    }

    /** Name the quality-hold location (null clears it, back to the code lookup). */
    public function setWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_KEY, $warehouseId);
    }

    /**
     * The location, or a 422 naming the fix.
     *
     * @throws ValidationException 422 naming the fix
     */
    public function warehouseOrFail(string $field = 'quality_hold'): Warehouse
    {
        $warehouse = $this->warehouse();

        if ($warehouse === null) {
            throw ValidationException::withMessages([$field => self::UNRESOLVED_MESSAGE]);
        }

        return $warehouse;
    }
}
