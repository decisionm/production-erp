import type { ConfigurationAbilities } from '@/components/configuration';
import type { Employee } from '@/features/hrms/types';
import type {
    CannotEstimateReason,
    FulfilmentPlanningBasis,
    Item,
    Warehouse,
} from '@/features/inventory/types';
import type { Vendor } from '@/features/procurement/types';
import type { TallyLink } from '@/features/sales/types';
import type { TallySyncStatus } from '@/features/tally-sync/types';

export interface WorkCenter {
    id: number;
    code: string;
    name: string;
    capacity_hours_per_day: string | null;
    is_active: boolean;
    created_at: string;
    // Machine capabilities. Null means "no limit known" — it never blocks;
    // only a stated limit constrains a configuration.
    capacity_class?: string | null;
    min_cavities?: number | null;
    max_cavities?: number | null;
    /** Explicit set for machines whose options are not a continuous range. */
    permitted_cavities?: number[] | null;
    cycle_time_min?: string | null;
    cycle_time_max?: string | null;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Optional: absent on a backend that predates the wiring, and the row
     * actions then offer nothing rather than invent permission. `delete:
     * null` on an index row means UNDETERMINED — the confirm asks.
     */
    can?: ConfigurationAbilities | null;
    default_shift_hours?: string | null;
    confirmation_status?: string | null;
}

export interface BomLine {
    id: number;
    component: Item;
    quantity_per: string;
}

export interface Bom {
    id: number;
    item: Item;
    name: string;
    version: string;
    is_active: boolean;
    lines: BomLine[];
    created_at: string;
}

export interface RoutingOperation {
    id: number;
    work_center: WorkCenter;
    sequence: number;
    name: string;
    standard_time_minutes: string | null;
}

export interface Routing {
    id: number;
    item: Item;
    name: string;
    is_active: boolean;
    operations: RoutingOperation[];
    created_at: string;
}

export type WorkOrderStatus = 'draft' | 'released' | 'completed';

export interface WorkOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_issued: string;
}

export interface ScrapReason {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    created_at: string;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

export interface Shift {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    is_active: boolean;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

/**
 * `cancelled` is a batch withdrawn as a mistake. It is a terminal state that
 * every "a batch that ran" query excludes by naming the other two, so a
 * cancelled row leaves the machine cards and the approval queue without any of
 * them being taught about it. The record itself is never deleted.
 */
export type BatchStatus = 'in_progress' | 'completed' | 'cancelled';
export type ShiftProductionEntryStatus =
    | 'pending'
    | 'pm_approved'
    | 'accountant_approved'
    | 'approved'
    | 'rejected'
    | 'synced'
    | 'failed';
export type ShiftScrapType = 'rejected_finished_good' | 'lumps';
/**
 * The stamps ProductionCalculationEngine writes on an entry
 * (VERSION_LEGACY · VERSION_FLOOR · VERSION_UNIFIED) — named so a screen
 * compares against a name it did not retype. The wire fields below
 * (`calculation_version` on ShiftProductionEntry, ProductionMetrics and
 * BatchEstimation) deliberately stay `string | null`: a stamp this build
 * does not know is still a stamp, and reading it as "legacy" is the
 * server's rule to make, not a type's (Phase 7, WS-C).
 */
export type CalculationVersion = 'legacy_v1' | 'production_v2_floor' | 'production_v3_unified';

/**
 * One material line issued at Complete Batch.
 *
 * `item` and `warehouse` are OPTIONAL on purpose, and this is not defensive
 * padding: ShiftMaterialConsumptionResource emits both only `whenLoaded`, so
 * any endpoint that returns an entry without eager-loading the relation drops
 * the key from the JSON entirely. Typing them as always-present is what let
 * `row.warehouse.code` ship and blank the approval drawer (30-Jul) — the type
 * must describe what the wire can actually carry.
 */
export interface ShiftMaterialConsumption {
    id: number;
    item?: Item | null;
    warehouse?: Warehouse | null;
    quantity_issued_kg: string;
    /**
     * Why the run consumed a material it was not planned on, and who
     * authorised it. Null on every ordinary line — an expected material needs
     * no reason and nobody's authority.
     */
    added_reason?: string | null;
    added_by?: number | null;
}

export interface ShiftScrap {
    id: number;
    type: ShiftScrapType;
    quantity_nos: string | null;
    quantity_kg: string | null;
    scrap_reason: ScrapReason | null;
}

export type ConsumptionNormSource = 'bom' | 'item_weight';

export interface ConsumptionVariance {
    /** How expected_kg was derived; null = no norm available. */
    norm_source: ConsumptionNormSource | null;
    /** Numeric string, e.g. "20.0000"; null when no norm or quantity_produced is null/0. */
    expected_kg: string | null;
    /** Sum of material consumption quantity_issued_kg, "0" if none. */
    actual_kg: string;
    /** actual - expected; null when expected_kg is null. */
    variance_kg: string | null;
    /** (actual-expected)/expected*100 rounded to 1 decimal; null when expected null or 0. */
    variance_pct: number | null;
    /** Server-ruled tolerance band (config-driven); null when pct is null. */
    variance_band?: 'ok' | 'watch' | 'investigate' | null;
    /** Entry quantity_rejection_kg or "0". */
    rejection_kg: string;
    /** Sum of scraps quantity_kg, "0". */
    scrap_kg: string;
    /** actual - expected - rejection - scrap; null when expected_kg null. */
    unaccounted_kg: string | null;
}

/**
 * Expected-output engine block, computed by the backend once a batch is
 * completed. Answers "did this machine produce what its cycle time says it
 * should have" — a different question from `ConsumptionVariance` (norm-based
 * material usage), which stays unchanged alongside it.
 */
export interface ProductionMetrics {
    /** (3600 / standard_cycle_time) × active_cavities × running_hours, 2dp — null if any input missing/zero. */
    expected_pieces: string | null;
    /** ROUND(expected_pieces / item.nos_per_box) — null if nos_per_box missing. */
    expected_boxes: number | null;
    /** expected_pieces / item.nos_per_pouch, rounded per production.packing_rounding — null if nos_per_pouch missing. */
    expected_pouches: number | null;
    /** = no_of_box as entered. */
    actual_boxes: number | null;
    /** = no_of_pouches as entered. */
    actual_pouches: number | null;
    /** = quantity_produced (box-first: frontend derives it, backend just reports). */
    actual_pieces: string | null;
    /**
     * actual_pieces / expected_pieces × 100 rounded 1dp — null when expected
     * pieces is null/0. CAN EXCEED 100: the ratio is honest, so a machine that
     * beat the standard cycle time it was measured against reads over 100 and
     * the screens say so rather than clamping it (see efficiency_band).
     */
    efficiency_pct: number | null;
    /**
     * `over_standard` is the band for a figure above 100% — not a better grade
     * than `ok` but a signal that a number is wrong: the produced count, the
     * hours or the cavities, or a standard cycle time set slower than the
     * machine really runs. Optional and possibly absent/legacy, which is why
     * every screen decides "over standard" from the percentage itself and uses
     * the band only as a second opinion.
     */
    efficiency_band?: 'ok' | 'watch' | 'investigate' | 'over_standard' | null;
    /** quantity_rejection_kg (pieces × g / 1000). */
    rejection_kg_production: string | null;
    /** qc_rejection_kg. */
    rejection_kg_qc: string | null;
    /** production − qc, null unless both present. */
    rejection_diff_kg: string | null;
    /** Sum of scraps type=lumps. */
    lumps_kg: string;
    /** Sum of material_consumptions quantity_issued_kg. */
    issued_kg: string;
    /** quantity_produced_kg. */
    good_production_kg: string | null;
    /** qc_rejection_kg ?? quantity_rejection_kg (QC wins when present). */
    confirmed_rejection_kg: string | null;
    /** issued − good − confirmed_rejection − lumps; null if issued==0 or good null. */
    reconciliation_unaccounted_kg: string | null;
    unaccounted_band?: 'ok' | 'investigate' | null;
    /**
     * WHICH FORMULA produced this block (Phase 5.5, P5.5-03): the entry's own
     * calculation_version stamp — production_v3_unified reads the engine
     * (cycles floored before cavities multiply); production_v2_floor /
     * legacy_v1 / null keep the inline WB2 figures they were signed against.
     * Optional: absent on a backend that predates the stamp being reported.
     */
    calculation_version?: string | null;
    /** True when expected_pieces is net of the completion-recorded downtime (both formulas net it; the key says so explicitly). */
    downtime_netted?: boolean;
    /** v3 only: the target BEFORE downtime (running hours as typed) — the engine's full_target. Null under the legacy formulas, which never had it. */
    expected_pieces_gross?: number | null;
    /** v3 only: expected_pieces_gross − expected_pieces — what the recorded stoppages cost, in whole shots' worth. Null under legacy. */
    downtime_impact_pieces?: number | null;
    /** True when the configured hard gate refuses accountant approval. */
    blocks_approval?: boolean;
    /**
     * Materials this batch issued more of than the ledger held — the balance
     * went NEGATIVE rather than the completion being refused.
     *
     * Optional and possibly absent: a backend older than negative-stock, or one
     * with the flag off, simply omits the key, and an empty array is the normal
     * answer for a batch that consumed nothing it did not have. Never treat
     * absence as "unknown" — treat it as "none".
     */
    stock_shortfalls?: StockShortfall[] | null;
    /**
     * Resin this batch named that the Store never issued to Production/WIP
     * (DEC-20260903-003) — the batch closed, the desk that signs it is told.
     * Same wire as stock_shortfalls: optional, absent on an older backend,
     * and an empty array is the normal answer. Absence means "none".
     */
    unissued_materials?: UnissuedMaterial[] | null;
}

/**
 * One resin line the Store had not handed to Production on any open Store
 * Issue when the batch consumed it (DEC-20260903-003). Frozen with its names
 * at completion, exactly as ShiftProductionEntryService::unissuedMaterials()
 * emits it; `quantity` is the kilograms the line consumed, in the item's own
 * unit.
 */
export interface UnissuedMaterial {
    item_id?: number | null;
    item_name?: string | null;
    item_uom?: string | null;
    quantity?: string | number | null;
    warehouse_id?: number | null;
    warehouse_name?: string | null;
    basis?: string | null;
}

/**
 * One material whose recorded stock could not cover what a batch consumed.
 *
 * THE SHIFT REALLY USED IT. A day bin holding zero RECORDED kg because nobody
 * entered the opening stock still had resin in it, and the truthful record is
 * the issue that happened — so the balance goes negative (Tally permits this
 * too) and the shortfall is raised at approval for the ACCOUNTANT to fix by
 * receiving the material or entering opening stock. It is never the
 * supervisor's problem and it never blocks the floor.
 *
 * THESE SIX KEYS ARE THE WHOLE WIRE SHAPE, and they are exactly what
 * ShiftProductionEntryService::stockShortfalls() emits — item_id, item_name,
 * item_uom, warehouse_id, warehouse_name, short_kg — frozen onto the entry's
 * config_snapshot at completion and read straight back off it. An earlier draft
 * of this file also declared requested_kg/available_kg/resulting_balance_kg and
 * `item`/`warehouse` relation objects "in case the resource lands under a
 * different name". The server never sent any of them, so the drawer printed
 * "— kg" for the one figure the whole screen exists to show. Speculative
 * aliases do not make a reader tolerant; they make it silently wrong. Only
 * `short_kg` is optional-shaped, and only because a backend predating this
 * change omits the block entirely.
 *
 * Note there is ONE quantity here, not two: the server records the gap
 * (bcsub(requested, balance), measured under the decrement's own row lock) and
 * not the pair it came from. The gap is the figure the accountant has to make
 * good, so it is the figure this screen prints.
 */
export interface StockShortfall {
    item_id?: number | null;
    item_name?: string | null;
    /**
     * The item's own unit, frozen with its name. Absent on snapshots written
     * before it was — the screen then prints the bare figure, because "kg"
     * beside a carton count is a wrong statement and no unit is merely a
     * missing one.
     */
    item_uom?: string | null;
    warehouse_id?: number | null;
    warehouse_name?: string | null;
    /** kg issued beyond the recorded balance. Numeric string — print, never round. */
    short_kg?: string | null;
    /**
     * WHAT THIS PARTICULAR SHORTFALL MEANS, when the location makes it a
     * different fact (Phase 7.5). Null — and absent on every snapshot
     * written before it existed — for an ordinary store, where a shortfall
     * is the stock record being behind and the accountant fixes the record.
     * Present for Production/WIP, which holds only what a store issue put
     * there: a gap there says the batch consumed more than was ever issued
     * to it, and nobody should be sent hunting a purchase receipt for it.
     */
    basis?: string | null;
}

/** A shortfall reduced to display strings. `shortKg` stays exact. */
export interface ReadableStockShortfall {
    key: string;
    /** Named, never "#592" — unless a name is genuinely all the server withheld. */
    item: string;
    warehouse: string;
    /** Numeric string as sent, trailing zeros trimmed; null when not stated. */
    shortKg: string | null;
    /**
     * The unit to print after the figure, or null for none.
     *
     * Not every shortfall is weighed: the approval desk read "4 kg of 15ml
     * Round Master Box" and "28 kg of 60 Ml Tray" (owner, 06-Aug) off a
     * hardcoded "kg". Cartons and trays are counted in Nos; only the resin and
     * the masterbatch are kilograms.
     */
    unit: string | null;
    /** The server's sentence about what this shortfall means, or null. */
    basis: string | null;
}

/**
 * "118.9980" → "118.998", "0.0000" → "0". Trailing zeros only — the figure is
 * NEVER rounded. The screen this feeds exists because a supervisor was told
 * "requested 118.998", and 119 is a different number from the one on the
 * refusal they were shown.
 *
 * The backend stores short_kg at 4dp, so this is what turns the stored
 * "118.9980" back into the incident's own figure.
 */
function trimNumeric(value: string | number | null | undefined): string | null {
    if (value === null || value === undefined) return null;
    const raw = String(value).trim();
    if (raw === '') return null;
    // Anything that is not a plain decimal is printed exactly as it arrived.
    if (!/^-?\d+(\.\d+)?$/.test(raw)) return raw;
    return raw.includes('.') ? raw.replace(/0+$/, '').replace(/\.$/, '') : raw;
}

/**
 * The shortfalls on a completed entry, in the shape a screen can print.
 *
 * `metrics.stock_shortfalls` is the ONLY source, because it is the only place
 * the server puts them: ShiftProductionEntryResource computes `metrics`
 * unconditionally (not `whenLoaded`), so the block survives the approval LIST
 * payload where `material_consumptions` and friends are deliberately not
 * eager-loaded — which is what lets a table row carry its own warning tag
 * before anyone opens the drawer. An earlier draft also read a top-level
 * `entry.stock_shortfalls`; no resource has ever exposed that key, so it was a
 * dead branch pretending to be a fallback.
 *
 * Absent or empty both mean "nothing went negative", never "unknown" — this
 * returns [] for a null entry, an older backend, and a clean batch alike.
 */
export function readStockShortfalls(
    entry: Pick<ShiftProductionEntry, 'metrics'> | null | undefined,
): ReadableStockShortfall[] {
    const lines = entry?.metrics?.stock_shortfalls;
    if (!Array.isArray(lines)) return [];

    // AN ID IS NEVER PRINTED AS THE NAME. "item #592 at warehouse #10" is the
    // exact sentence that started all this, and reprinting it one screen later
    // — on the screen where it is signed — would move the defect rather than
    // fix it. The server freezes both names at completion precisely so a later
    // rename or soft delete cannot degrade this back to numbers; if it withheld
    // one anyway, the line says so in words and parks the id in brackets where
    // it can only be read as a debugging aid.
    return lines.map((line, index) => ({
        key: `${line.item_id ?? 'item'}-${line.warehouse_id ?? 'wh'}-${index}`,
        item:
            (line.item_name ?? '').trim() ||
            (line.item_id != null ? `unnamed material (id ${line.item_id})` : 'unnamed material'),
        warehouse:
            (line.warehouse_name ?? '').trim() ||
            (line.warehouse_id != null ? `unnamed store (id ${line.warehouse_id})` : 'unnamed store'),
        shortKg: trimNumeric(line.short_kg),
        // Printed exactly as the item master spells it — this factory's Tally
        // writes "Kgs.", "Nos", "KGS" — because that is the unit the store
        // counts in, and tidying it here would invent a unit of our own.
        unit: (line.item_uom ?? '').trim() || null,
        // Printed verbatim when present, never composed here: which location
        // makes a shortfall mean what is the server's call, and a sentence
        // this side invented could contradict it.
        basis: (line.basis ?? '').trim() || null,
    }));
}

export interface ReadableUnissuedMaterial {
    key: string;
    item: string;
    warehouse: string;
    /** Numeric string as sent, trailing zeros trimmed; null when not stated. */
    quantity: string | null;
    unit: string | null;
    basis: string | null;
}

/**
 * The un-issued resin lines on a completed entry, in the shape a screen can
 * print — `metrics.unissued_materials` only, for the same reason
 * readStockShortfalls() reads only `metrics.stock_shortfalls`. Names, never
 * ids, with the same fallback wording.
 */
export function readUnissuedMaterials(
    entry: Pick<ShiftProductionEntry, 'metrics'> | null | undefined,
): ReadableUnissuedMaterial[] {
    const lines = entry?.metrics?.unissued_materials;
    if (!Array.isArray(lines)) return [];

    return lines.map((line, index) => ({
        key: `${line.item_id ?? 'item'}-${line.warehouse_id ?? 'wh'}-${index}`,
        item:
            (line.item_name ?? '').trim() ||
            (line.item_id != null ? `unnamed material (id ${line.item_id})` : 'unnamed material'),
        warehouse:
            (line.warehouse_name ?? '').trim() ||
            (line.warehouse_id != null ? `unnamed store (id ${line.warehouse_id})` : 'unnamed store'),
        quantity: trimNumeric(line.quantity),
        unit: (line.item_uom ?? '').trim() || null,
        basis: (line.basis ?? '').trim() || null,
    }));
}

/**
 * The quality gate's block on a shift production entry, exactly as
 * ShiftProductionEntryResource serves it under the `quality` key.
 *
 * ALWAYS PRESENT, all-null before the check — the backend emits the block
 * unconditionally so a client can tell "not checked yet" from "this backend
 * has no gate at all" without having to tell a missing key from a null one.
 * Optional here only for payloads that predate the gate.
 *
 * Counts (`*_nos`) are whole BOTTLES. `rejection_kg` is the kilogram figure
 * the SERVER derives from them (pieces × the run's frozen unit weight); the
 * frontend never does that conversion, because the gram weight is not its to
 * choose.
 */
export interface EntryQuality {
    /** Has quality checked this batch? The precondition the PM gate waits on. */
    checked: boolean;
    /**
     * Is the gate switched on for this deployment
     * (config `production.approvals.quality_stage_enabled`)?
     *
     * Served PER ENTRY rather than on /production/settings, so the flag and
     * the check it governs always travel together and can never disagree.
     */
    stage_enabled: boolean;
    reviewed_nos: number | null;
    ok_nos: number | null;
    rejected_nos: number | null;
    checked_at: string | null;
    note: string | null;
    /** The supervisor's original count, before the gate reduced it. */
    gross_quantity_produced: string | null;
    /** What production now stands at — the same figure as `quantity_produced`. */
    net_quantity_produced: string | null;
    /** Kilograms the rejection removed; null when nothing was rejected. */
    rejection_kg: string | null;
    /**
     * How the rejected pieces became kilograms. `unit_weight_grams` is a
     * DECIMAL STRING, not a number — it comes off the run's frozen
     * config_snapshot (or the item master) as `"12.9000"` and the server
     * never casts it. Parse it with `readQuantity` before doing arithmetic.
     */
    rejection_kg_basis: { unit_weight_grams: string | null; source: string | null } | null;
    /**
     * Set when the rejected pieces were issued out of finished goods but their
     * mass could NOT be received as scrap, because this ERP has no scrap-item
     * master yet. A sentence to show the approver, not an error.
     */
    scrap_note: string | null;
    /** whenLoaded — absent on payloads that don't eager-load the checker. */
    checked_by?: { id: number; name: string } | null;
}

/**
 * Is the quality gate switched on, according to this entry?
 *
 * OFF UNLESS THE ENTRY SAYS OTHERWISE, and the direction is the whole point.
 * A payload from a backend that predates the gate carries no `quality` block
 * at all; reading that as "on" would disable every approve button in the
 * factory and stop the day's production reaching Tally. Absent block, absent
 * flag, anything not literally `true` — all read as off, and with it off the
 * approval page behaves exactly as it did before the gate existed.
 *
 * (The backend's own gate is deliberately fail-CLOSED — pmApprove() refuses an
 * unchecked batch. That is the right default for a rule that protects the
 * books. This reader governs only what the SCREEN draws, where guessing "on"
 * from missing data would grey out buttons the server would happily accept.)
 */
export function readQualityStageEnabled(
    entry: Pick<ShiftProductionEntry, 'quality'> | null | undefined,
): boolean {
    return entry?.quality?.stage_enabled === true;
}

/** The entry's quality block, or null on a backend that predates the gate. */
export function readQuality(
    entry: Pick<ShiftProductionEntry, 'quality'> | null | undefined,
): EntryQuality | null {
    return entry?.quality ?? null;
}

/** Has quality checked this batch? False also covers "this backend has no gate". */
export function isQualityChecked(
    entry: Pick<ShiftProductionEntry, 'quality'> | null | undefined,
): boolean {
    return entry?.quality?.checked === true;
}

/** One time quality sent this batch back to the floor. */
export interface EntryCorrectionReturn {
    returned_by: number | null;
    returned_at: string | null;
    reason: string | null;
    /** Was a recorded check unwound by this return, or had none been made yet? */
    cleared_quality_check?: boolean;
}

/**
 * One time the floor re-entered its own completion figures.
 *
 * The named fields are exactly what `amendCompletion` writes onto the entry's
 * frozen snapshot. They are declared rather than left to the index signature
 * because `[key: string]: unknown` types every read as `unknown` — a screen
 * printing `previous_quantity_produced` would then need a cast at each site,
 * and a cast is where a wire-shape change stops being a type error.
 *
 * `amended_by`/`previous_completed_by` are users.id, NOT names: the snapshot
 * stores ids and the resource does not resolve them. A screen that wants a
 * name must look it up and must stay readable when it cannot.
 */
export interface EntryCorrectionAmendment {
    amended_by?: number | null;
    amended_at?: string | null;
    reason?: string | null;
    /** How many of quality's returns this amendment answered. */
    answered_returns?: number;
    /** The produced figure this correction replaced — a decimal string. */
    previous_quantity_produced?: string | null;
    /** Who had completed the batch before this correction (users.id). */
    previous_completed_by?: number | null;
    [key: string]: unknown;
}

/**
 * The LAST time Quality sent this batch back, exactly as
 * ShiftProductionEntryResource serves it under the `quality_return` key
 * (Task 1 of the "Returned by Quality" plan, 03-Sep-2026) — the queue-row
 * answer, with the returning user's name already resolved rather than an id
 * a badge would have to look up itself.
 *
 * `times` counts every return this batch has EVER had; the other three
 * fields describe only the latest one, the instruction currently on record.
 * The full history (every return, every amendment, raw `returned_by` ids)
 * is `EntryCorrection` below — Production's own screen, which already knows
 * who its users are. This key exists for screens that do not, and for the
 * one-line "has this ever happened" tag (`returnedTagText`,
 * features/quality/returnedByQuality.ts) both the Quality queue and the
 * Shift Floor render from it.
 */
export interface EntryQualityReturn {
    returned_by_name: string | null;
    returned_at: string | null;
    reason: string | null;
    times: number;
}

/**
 * What has been done to this batch since it was completed, exactly as
 * ShiftProductionEntryResource serves it under the `correction` key
 * (ShiftProductionEntryService::correctionHistory).
 *
 * ALWAYS PRESENT with empty lists, same rule as `quality` — a client can say
 * "nothing has happened to this batch" without having to tell a missing key
 * apart from a null one. Optional here only for payloads that predate the
 * two correction doors.
 */
export interface EntryCorrection {
    /**
     * Quality returned this batch and nobody has re-checked it since. THE
     * SERVER'S OWN FLAG, never recomputed here: it already folds in the
     * status, the batch status and the absence of a check, and it is the one
     * thing that separates "waiting for its first check" from "sent back to
     * the floor" — two states that otherwise look identical, both being
     * status `pending` with `quality.checked === false`.
     */
    awaiting_correction: boolean;
    /** The reason on the most recent return — the only instruction the floor gets. */
    latest_return_reason: string | null;
    returns: EntryCorrectionReturn[];
    amendments: EntryCorrectionAmendment[];
}

/** The entry's correction block, or null on a backend that predates it. */
export function readCorrection(
    entry: Pick<ShiftProductionEntry, 'correction'> | null | undefined,
): EntryCorrection | null {
    return entry?.correction ?? null;
}

/**
 * Has quality sent this batch back for correction?
 *
 * FALSE UNLESS THE SERVER SAYS OTHERWISE, and the direction matters for the
 * same reason `readQualityStageEnabled` reads off: a backend without the
 * correction doors sends no `correction` block, and reading that as "returned"
 * would paint every pending batch in the factory amber and drop it into the
 * production queue as work to redo.
 */
export function isAwaitingCorrection(
    entry: Pick<ShiftProductionEntry, 'correction'> | null | undefined,
): boolean {
    return entry?.correction?.awaiting_correction === true;
}

/** Why quality sent it back, or null when it never did. */
export function readReturnReason(
    entry: Pick<ShiftProductionEntry, 'correction'> | null | undefined,
): string | null {
    const reason = (entry?.correction?.latest_return_reason ?? '').trim();
    return reason === '' ? null : reason;
}

/**
 * May the floor still correct its own completion figures?
 *
 * READ OFF THE ENTRY'S OWN FIELDS, never off the caller's role or a guess:
 * the batch is completed, it is still `pending` (so no approval has been
 * signed and no voucher is on its way), and quality has not checked it. Those
 * are precisely the conditions `ShiftProductionEntryService::amendCompletion`
 * tests before it will do anything.
 *
 * IT IS NOT THE GATE. The server refuses several cases this cannot see from a
 * list payload — a batch already sitting on a Tally voucher, a segment whose
 * child shift opened from its closing weights — and it refuses them with a
 * sentence in factory words that the screen shows. This only decides whether
 * offering the door is honest; the server decides whether it opens.
 */
export function canAmendCompletion(
    entry:
        | Pick<ShiftProductionEntry, 'status' | 'batch_status' | 'quality'>
        | null
        | undefined,
): boolean {
    if (!entry) return false;
    return (
        entry.status === 'pending'
        && entry.batch_status === 'completed'
        && entry.quality?.checked !== true
    );
}

/**
 * Parse a decimal-string quantity into a number, or null when it is absent or
 * not a plain number. Never returns NaN — a NaN would propagate silently into
 * a production figure printed beside an approve button.
 */
export function readQuantity(raw: string | number | null | undefined): number | null {
    if (raw === null || raw === undefined || raw === '') return null;
    const n = typeof raw === 'number' ? raw : Number(raw);
    return Number.isFinite(n) ? n : null;
}

/**
 * The net good production figure a PM is approving.
 *
 * READ, NEVER RECOMPUTED. When the check is recorded the server rewrites
 * `quantity_produced` itself to the net figure — that is what "the total
 * production will reduce" means, and it is what the voucher's produced line
 * and every report then carry — preserving the supervisor's original count in
 * `gross_quantity_produced`. Subtracting the rejected count here would deduct
 * it a SECOND time and print a figure lower than the one actually posting to
 * Tally.
 */
export function netProducedPieces(
    entry: Pick<ShiftProductionEntry, 'quantity_produced' | 'quality'> | null | undefined,
): number | null {
    return readQuantity(entry?.quality?.net_quantity_produced ?? entry?.quantity_produced);
}

/**
 * The supervisor's original count, before the gate reduced it. Falls back to
 * `quantity_produced`, which IS the gross figure until a check is recorded.
 */
export function grossProducedPieces(
    entry:
        | Pick<ShiftProductionEntry, 'quantity_produced' | 'gross_quantity_produced' | 'quality'>
        | null
        | undefined,
): number | null {
    return readQuantity(
        entry?.quality?.gross_quantity_produced ?? entry?.gross_quantity_produced ?? entry?.quantity_produced,
    );
}

/**
 * WHAT THIS BATCH'S RESIN COST, per material — the finance-only detail
 * behind `BatchCost.resin_cost`.
 *
 * ONE ROW PER EXACT MATERIAL, drawn from that material's COMMON POOL at the
 * pool's weighted average. THERE ARE NO BAG OR LOT IDENTITIES IN IT, at any
 * permission level. The owner's correction (2-Aug) removed the ground the
 * old per-bag layer stood on: with one common resin input serving every
 * machine there is no physical path from a bag to a batch, and naming a bag
 * barcode or a supplier lot against a batch was the most confidently wrong
 * thing this screen could do.
 *
 * `pool_rate`/`amount` are null when nothing priced stands in the pool and
 * there is no stock average to fall back on either. That is a real state and
 * it is why the whole batch total goes null rather than quietly costing that
 * resin at zero.
 */
export interface BatchCostAllocation {
    item_id: number;
    item_name: string | null;
    /** The pool's weighted average at allocation time — frozen, never re-read. */
    pool_rate: string | null;
    /** Kg of this material drawn for this batch — 4dp decimal string. */
    quantity: string;
    amount: string | null;
    /**
     * How the rate above was arrived at — the common pool's weighted average,
     * the stock average used as a fallback when consumption ran past what the
     * pool held, or an unpriced slice. Rendered verbatim; this client does not
     * enumerate the vocabulary, because a value it has never heard of must
     * still reach the screen rather than being silently dropped.
     */
    rate_source: string | null;
    /**
     * The accounting-allocation sentence, repeated per row by the server.
     * DO NOT RENDER IT PER ROW — `BatchCost.basis` carries the same sentence
     * once, ungated, and one claim said once is the claim this module makes.
     */
    sentence: string;
}

/**
 * A non-resin consumption line — masterbatch, packaging, anything the floor
 * did not scan — priced exactly the way `material_cost` prices it, at the
 * unit cost its own issue movement recorded.
 */
export interface BatchCostOtherLine {
    item_id: number;
    item_name: string | null;
    warehouse_id: number;
    quantity_issued_kg: string;
    unit_cost: string | null;
    cost: string | null;
}

/**
 * WHAT THIS BATCH COST — resin allocated out of the COMMON RESIN POOL at its
 * weighted average, everything else at its recorded issue cost.
 *
 * IT IS AN ACCOUNTING ALLOCATION, NOT TRACEABILITY, and `basis` says so in
 * the server's own words. Print `basis` wherever any of these figures is
 * printed: the factory records no bag-to-batch link, and a cost figure shown
 * without that sentence is a claim this module does not make.
 *
 * ALWAYS PRESENT on a completed-or-not entry: before completion every figure
 * is null and `reason` says so in words. Never tell a null total apart from
 * a missing key — read `reason` first and print it.
 *
 * EVERY MONEY FIELD IS A DECIMAL STRING OR NULL, never a number: these are
 * bcmath figures on the wire and parsing them into JS floats to display them
 * is how a paisa goes missing. null NEVER means zero — it means "this cannot
 * be costed in full", and `reason` names which of the two ways it failed.
 *
 * `allocations` and `other_lines` are ABSENT (not null, not empty) for anyone
 * without finance.view/finance.manage — the rates are Owner and Accounts
 * territory. Gate the breakdown on the KEY BEING PRESENT rather than on a
 * permission check of your own: the server has already decided, and a second
 * opinion here can only disagree with it. `basis` is NOT part of that gate —
 * it is what the number means, not anatomy, and everyone sees it.
 */
export interface BatchCost {
    /**
     * When this figure was READ, not when it was costed — the server stamps
     * it at serialization. Label it as such; a batch costed last Tuesday and
     * opened today shows today.
     */
    as_of: string;
    /** Why a figure below is null, in a sentence. null when all is well. */
    reason: string | null;
    /**
     * Which allocation pass produced these rows. Bumps on every amendment —
     * an amended batch reverses run N and re-allocates as run N+1, so a
     * number above 1 means this batch has been corrected.
     */
    allocation_run: number | null;
    material_cost_total: string | null;
    resin_cost: string | null;
    other_cost: string | null;
    cost_per_accepted_unit: string | null;
    accepted_quantity: string | null;
    /**
     * THE ONE SENTENCE THESE FIGURES MUST NEVER BE SHOWN WITHOUT: what the
     * costing basis is, and what it is not. The server sends it on EVERY
     * return path and at every permission level (BagCostAllocationService::
     * ALLOCATION_SENTENCE), which is why it is required here rather than
     * optional with a local fallback — a second copy of the sentence living
     * in this client is exactly the drift the backend constant exists to
     * prevent.
     */
    basis: string;
    /**
     * Machine-readable provenance per figure; see `batchCostSourceLabel`.
     *
     * OPTIONAL because it is READ optionally, and the two must agree. The
     * server always sends it — but this drawer has already been blanked once
     * by an unguarded dereference of a key that was only sometimes there
     * (see the material-consumption `From` column), and a missing source
     * line is worth a dash, never a white screen over the approval queue.
     */
    sources?: {
        resin_cost: string;
        other_cost: string;
        cost_per_accepted_unit: string;
    };
    /** Finance only — ABSENT otherwise. Carries no bag or lot identity. */
    allocations?: BatchCostAllocation[];
    /** Finance only — ABSENT otherwise. */
    other_lines?: BatchCostOtherLine[];
}

/**
 * The `sources` tokens in the words a plant manager reads. Unknown tokens
 * fall through UNCHANGED rather than to a guess or a blank — a source line
 * this client has not been taught is still better evidence than no line.
 */
export function batchCostSourceLabel(source: string | null | undefined): string {
    if (!source) return '—';
    switch (source) {
        case 'common_resin_pool_weighted_average':
            return "the common resin pool's weighted average for each material";
        case 'issue_unit_cost':
            return 'the cost recorded when each material was issued';
        case 'quantity_produced_qc_net':
            return 'accepted pieces, after QC rejection';
        default:
            return source;
    }
}

export interface ShiftProductionEntry {
    /** The expected-output formula this batch was started under (production_v3_unified since Phase 5.5; production_v2_floor / null before). The server's metrics and the page's pre-submit mirror both read it. */
    calculation_version?: string | null;
    id: number;
    shift: Shift;
    work_center: WorkCenter;
    item: Item;
    warehouse: Warehouse;
    production_date: string;
    /**
     * Traceability (Phase 6): set when this entry is a shift SEGMENT opened by
     * a handover — same batch_number/product as the parent, day-bin balance
     * carried in as the opening. Absent/null on pre-traceability backends.
     */
    parent_entry_id?: number | null;
    batch_status: BatchStatus;
    /**
     * Configurable-production provenance, frozen at Start: the standard the
     * run started against, WHICH packaging row of it (two same-mode packings
     * can coexist on one standard — Phase 5, D1 — so the mode alone no
     * longer names one), and the mode. The completion drawer seeds its
     * packing line from the packaging id first and falls back to the mode
     * only for a batch started before the id was frozen. All optional: a
     * backend that predates them omits them; null is "none was chosen".
     */
    production_standard_id?: number | null;
    production_standard_packaging_id?: number | null;
    packaging_mode?: string | null;
    /**
     * WHICH COLOUR THIS RUN IS RECORDED AS MAKING — read back out of the
     * config snapshot Start Batch froze it into, so a later item-master edit
     * cannot restate it.
     *
     * This, not `item.colour`, is what picks the masterbatch: most bottle
     * items carry no colour at all (which is why Start Batch asks), and a
     * mislabelled one names a different colour's material. Optional because a
     * backend that predates the field simply omits it; null is the real
     * answer "nobody stated a colour", never "".
     */
    colour?: string | null;
    /**
     * What one bottle of THIS run weighs, frozen at Start into the same
     * snapshot as the colour above — and the weight the server itself uses.
     *
     * `resolvedUnitWeightGrams()` reads this first and the item master only as
     * a fallback, then computes every kilogram stored on the entry from it. A
     * screen that previews its kilograms from the item master alone agrees
     * whenever no configuration overrode the weight, and diverges silently the
     * moment one does.
     *
     * Optional because a backend predating the field omits it; null is the real
     * answer "this run froze no weight", in which case the item master's figure
     * IS the truth rather than a guess at it.
     */
    unit_weight_grams?: string | null;
    batch_number: string | null;
    /**
     * NET of any quality rejection. The gate rewrites this column so every
     * consumer — this screen, the reports, the Tally voucher's produced line
     * — carries the same reduced figure without needing to know the gate
     * exists. The supervisor's original count survives in
     * `gross_quantity_produced`.
     */
    quantity_produced: string | null;
    quantity_produced_kg: string | null;
    /** The supervisor's count before the gate; null until a check happens. */
    gross_quantity_produced?: string | null;
    quantity_scrap: string;
    quantity_rejection_kg: string | null;
    scrap_reason: ScrapReason | null;
    nos_per_tray: number | null;
    no_of_trays: number | null;
    nos_per_box: number | null;
    no_of_box: number | null;
    /** Pouch count entered at Complete Batch (items with a pouch standard). */
    no_of_pouches: number | null;
    /** Loose pieces beyond full boxes/pouches — persisted since Wave A packaging. */
    loose_pieces: number | null;
    /** SNAPSHOT copied from the item at Start Batch — never editable after. */
    standard_cycle_time: string | null;
    actual_cycle_time: string | null;
    /**
     * MISLEADING NAME, kept because every past batch carries it: this is filled
     * CONFIGURATION-FIRST, so it is the governing figure for the run and not
     * necessarily the Excel workbook's. Read `figure_sources` below to tell the
     * three apart, and never label this one "std".
     */
    standard_cavities: number | null;
    /** Editable; defaults to standard. */
    active_cavities: number | null;
    /**
     * The three figures kept apart, so a screen can show the workbook's value,
     * the machine exception's value and the run's own without presenting one as
     * another. This is the fix for a supervisor reading "std: 4" on a product
     * whose workbook row says 5.
     */
    figure_sources?: {
        product_standard: { cavities: number | null; cycle_time: string | null; source_reference: string | null; label: string };
        machine_configuration: { cavities: number | null; cycle_time: string | null; approved_by_person: boolean; label: string };
        active: { cavities: number | null; cycle_time: string | null; cavities_source: string | null; cycle_time_source: string | null; label: string };
    };
    cancelled_at?: string | null;
    cancelled_by?: { id: number; name: string } | null;
    cancellation_reason?: string | null;
    /** Entered at Complete Batch. */
    running_hours: string | null;
    /** Entered at/after completion. */
    qc_rejection_kg: string | null;
    /**
     * The quality gate — the stage the owner asked for between Complete Batch
     * and PM approval ("all the machines will go to quality queue"). Always
     * served by a backend that has the gate, all-null before the check.
     * Optional only for payloads that predate it; read through
     * `readQuality` / `isQualityChecked` / `readQualityStageEnabled`.
     */
    quality?: EntryQuality | null;
    /**
     * The two correction doors: quality's returns and the floor's own
     * amendments. Always served (empty lists before anything happens) by a
     * backend that has them; optional only for payloads that predate them.
     * Read through `readCorrection` / `isAwaitingCorrection` /
     * `readReturnReason`.
     */
    correction?: EntryCorrection | null;
    /**
     * WAS THIS BATCH EVER SENT BACK BY QUALITY, and what did the LAST return
     * say — null when it never has been. See `EntryQualityReturn` above for
     * why this exists beside `correction`. Optional only for a backend that
     * predates the key; read through `returnedTagText`
     * (features/quality/returnedByQuality.ts).
     */
    quality_return?: EntryQualityReturn | null;
    /**
     * Both collections are `whenLoaded` on the backend resource, and the
     * approval/reject/start endpoints deliberately don't load them — so they
     * are ABSENT, not empty, on those payloads. Read them as `?? []`.
     */
    material_consumptions?: ShiftMaterialConsumption[];
    scraps?: ShiftScrap[];
    /** Downtime logged at Start or with the completion; absent when not loaded. */
    downtime_events?: ProductionDowntimeEvent[];
    /** Null when batch_status is not completed (no consumption yet). */
    variance: ConsumptionVariance | null;
    /**
     * Null for non-completed batches — the frontend duplicates the expected_*
     * formula for the live running screen; backend is authoritative after
     * completion.
     */
    metrics: ProductionMetrics | null;
    /**
     * What this batch cost, from the bags its resin actually came out of.
     * Optional only because a backend that predates the costing layer omits
     * it entirely; on a backend that has it, it is always an object (with
     * nulls and a `reason` before completion), never null. See `BatchCost`.
     */
    batch_cost?: BatchCost | null;
    /** Latest Tally sync error — present only when status is "failed". */
    sync_error?: string | null;
    /**
     * WHERE THIS BATCH STANDS IN TALLY (Phase 5.5, WS-C) — the TallyLink of
     * the voucher that CARRIES the entry: under shift granularity the Stock
     * Journal it was stamped onto (the voucher's syncable is the Shift, the
     * entry hangs off tally_sync_entry_id), else its own per-entry voucher.
     * Status + voucher number + deep link, never the payload; null means NO
     * voucher exists yet, which is a different fact from "pending" and is
     * rendered as a dash, never as a state. Optional only for a backend
     * that predates the key.
     */
    tally?: TallyLink | null;
    /**
     * The Tally identity this batch's finished goods post as
     * (DEC-20260810-003) — frozen at completion when the selected packaging
     * carries its own item; null means the product's item. Optional only for
     * a backend that predates the key.
     */
    finished_item?: { id: number; name: string } | null;
    /**
     * WHAT THE COMPLETION DRAWER IS PRE-FILLED WITH (Phase 5.5, WS-B): the
     * five pack counts through the ONE reader (PackQuantityResolver::forEntry
     * — the typed entry columns once there are any, else the run's frozen
     * packaging row, else the item master), each figure labelled with the
     * rung that answered it in `sources`; `source` is the rung the block as
     * a whole came from. A figure no rung holds is null, never invented.
     * Optional only for a backend that predates the key.
     */
    packing_defaults?: PackingDefaults | null;
    /**
     * WHAT THE RUN'S CONFIGURATION WAS STILL MISSING (Phase 5.5, WS-B), in
     * the server's vocabulary (ProductVariantService::MISSING_VOCABULARY, in
     * its order — worded on screen by missingWords()), judged for THIS run's
     * frozen standard and packaging, not the standard's union. `source`
     * says whether it was frozen at Start ('snapshot' — every batch started
     * since the fix loop; a later master edit never restates it) or computed
     * now from the frozen ids ('live' — a run from before the snapshot
     * existed). Completed Today's "config incomplete" tag reads it. Optional
     * only for a backend that predates the key.
     */
    configuration_gaps?: ConfigurationGaps | null;
    status: ShiftProductionEntryStatus;
    rejection_reason: string | null;
    plant_manager_signed_by?: { id: number; name: string } | null;
    plant_manager_signed_at?: string | null;
    accountant_signed_by?: { id: number; name: string } | null;
    accountant_signed_at?: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    operator: Employee | null;
    helper_name: string | null;
    notes: string | null;
    created_at: string;
}

/** Where a pre-filled pack count came from — the rung of PackQuantityResolver that answered. */
export type PackingDefaultsSource = 'entry' | 'packaging' | 'item' | 'none';

export interface PackingDefaults {
    nos_per_box: number | null;
    nos_per_tray: number | null;
    trays_per_box: number | null;
    nos_per_pouch: number | null;
    pouches_per_box: number | null;
    /** The rung the block as a whole came from. */
    source: PackingDefaultsSource;
    /** The rung per figure — a box count from the entry beside a tray count from the packaging row. */
    sources: Record<'nos_per_box' | 'nos_per_tray' | 'trays_per_box' | 'nos_per_pouch' | 'pouches_per_box', PackingDefaultsSource>;
}

export interface ConfigurationGaps {
    complete: boolean;
    /** Server keys, in vocabulary order ("counts", "tally_identity", …) — word them with missingWords(). */
    missing: string[];
    /** 'snapshot' = frozen at Start; 'live' = computed now from the frozen ids (a run from before the snapshot existed). */
    source: 'snapshot' | 'live';
}

export interface ShiftSummary {
    id: number;
    shift: Shift;
    production_date: string;
    supervisor: Employee | null;
    target_production_kg: string | null;
    power_consumption_units: string | null;
    remarks: string | null;
    created_at: string;
}

export type LogStatus = 'open' | 'closed';

export interface MachineDowntimeLog {
    id: number;
    work_center: WorkCenter;
    shift: Shift;
    production_date: string;
    nature_of_problem: string;
    remedy: string | null;
    parts_changed: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export type MoldStatus = 'active' | 'under_repair' | 'retired';

export interface Mold {
    id: number;
    code: string;
    name: string;
    cavity_count: number | null;
    status: MoldStatus;
    notes: string | null;
    created_at: string;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

export interface MoldChangeLog {
    id: number;
    work_center: WorkCenter;
    shift: Shift;
    production_date: string;
    changed_from_item: Item | null;
    changed_from_mold: Mold | null;
    changed_to_item: Item;
    changed_to_mold: Mold | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface PowerInterruptionLog {
    id: number;
    shift: Shift;
    production_date: string;
    from_time: string;
    to_time: string;
    idle_hours: string;
}

export interface ShiftStockCount {
    id: number;
    shift: Shift;
    production_date: string;
    location_label: string;
    item: Item;
    quantity_kg: string;
}

export interface ShiftKpiItemBreakdown {
    item: { id: number; sku: string; name: string };
    batches: number;
    quantity_produced: string;
    quantity_produced_kg: string;
    quantity_rejected: string;
    quantity_rejection_kg: string;
}

export interface ShiftKpiDowntimeLog {
    id: number;
    work_center: string;
    nature_of_problem: string;
    remedy: string | null;
    parts_changed: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface ShiftKpiMoldChangeLog {
    id: number;
    work_center: string;
    changed_from: string | null;
    changed_from_mold: string | null;
    changed_to: string;
    changed_to_mold: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface ShiftKpiPowerInterruptionLog {
    id: number;
    from_time: string;
    to_time: string;
    idle_hours: string;
}

export interface ShiftKpiStockCount {
    id: number;
    location_label: string;
    item: { id: number; sku: string; name: string };
    quantity_kg: string;
}

export interface ShiftKpiReport {
    shift_id: number | null;
    production_date: string;
    target_production_kg: string | null;
    actual_production_kg: string;
    rejection_kg: string;
    net_good_output_kg: string;
    efficiency_percent: number | null;
    /**
     * What efficiency_percent is measured AGAINST (Phase 5.7): the
     * supervisor-typed target_production_kg — the only basis the server has
     * — or null when no target was typed and the percentage is null too.
     * Optional: a backend that predates the key omits it.
     */
    efficiency_basis?: 'supervisor_target' | null;
    /**
     * Where the two raw inputs came from (Phase 5.7): 'supervisor' when a
     * summary row on this date carries the figure, null when none does —
     * the report never invents a target or a power reading. Optional: a
     * backend that predates the key omits it.
     */
    kpi_inputs?: {
        target_production_kg: 'supervisor' | null;
        power_consumption_units: 'supervisor' | null;
    };
    rejection_percent: number | null;
    /**
     * LIVE counts, not the date's fact: machines_running is the machines with
     * a batch in progress RIGHT NOW (filed under this date/shift) and
     * machines_down the machines with an OPEN breakdown right now. Phase 5.7
     * names them so — `machines_running_now` / `machines_down_now` — and keeps
     * the old keys as aliases for one release; read the new key first and
     * fall back to the old.
     */
    machines_running: number;
    machines_down: number;
    machines_running_now?: number;
    machines_down_now?: number;
    idle_time_hours: string;
    no_of_mold_changes: number;
    power_consumption_units: string | null;
    unit_per_kg: number | null;
    power_interruption_hours: string;
    remarks: string | null;
    supervisor: Employee | null;
    items_manufactured: ShiftKpiItemBreakdown[];
    downtime_logs: ShiftKpiDowntimeLog[];
    mold_change_logs: ShiftKpiMoldChangeLog[];
    power_interruption_logs: ShiftKpiPowerInterruptionLog[];
    stock_counts: ShiftKpiStockCount[];
}

/**
 * GET production/cec (Phase 5.7, WS-B) — the CEC's DATA, composed thinly on
 * the server from the two things the factory already computes for a date:
 * the Shift KPI Summary (per shift, and the day) and the entries index's
 * completed rows (the Completed Today read), grouped by machine. Every
 * figure IS one of theirs; the only server-side arithmetic is a plain sum,
 * labelled as one (`sums.basis`). THE FORMAT IS NOT KNOWN: no CEC sample
 * exists in the repo, the export kind stays blocked, and `format` says so
 * verbatim. The frontend previews these figures and computes nothing.
 */
export interface CecBatch {
    entry_id: number;
    batch_number: string | null;
    item: { id: number; sku: string | null; name: string | null } | null;
    /** metrics.expected_pieces — null when the run had no standard. */
    expected_pieces: string | null;
    actual_pieces: string | null;
    good_production_kg: string | null;
    /** metrics.rejection_kg_production. */
    rejection_kg: string | null;
    /** metrics.rejection_kg_qc — null until quality weighs it. */
    rejection_kg_qc: string | null;
    efficiency_pct: number | null;
    efficiency_band: ProductionMetrics['efficiency_band'] | null;
    expected_boxes: number | null;
    /** no_of_box as entered at Complete Batch. */
    packs: number | null;
    /** metrics.downtime_minutes_total ("30.00"). */
    downtime_minutes_total: string | null;
    calculation_version: string | null;
    approval_status: ShiftProductionEntryStatus | null;
    tally_status: TallySyncStatus | null;
    tally: TallyLink | null;
}

/**
 * A plain sum over a block's batches — every key the server's, a null where
 * there was nothing to sum (never an invented zero), `skipped_nulls` naming
 * how many batches each figure could not include, `basis` the sentence
 * that says so.
 */
export interface CecSums {
    batches: number;
    expected_pieces: string | null;
    actual_pieces: string | null;
    good_production_kg: string | null;
    rejection_kg: string | null;
    packs: number | null;
    downtime_minutes_total: string | null;
    skipped_nulls: Record<string, number>;
    basis: string;
}

export interface CecMachineBlock {
    /** The work centre as the index knows it; code/name null for a machine since deleted. */
    machine: { id: number; code: string | null; name: string | null; display_sequence?: number | null };
    batches: CecBatch[];
    sums: CecSums;
}

export interface CecShiftBlock {
    shift: { id: number; name: string; start_time?: string; end_time?: string };
    /** ShiftSummaryService::report for this shift and date, verbatim. */
    summary: ShiftKpiReport;
    machines: CecMachineBlock[];
    sums: CecSums;
}

export interface CecReport {
    /** The server's statement of the CEC format state, shown verbatim. */
    format: string;
    figures_from: string[];
    production_date: string;
    shift_id: number | null;
    scope: 'day' | 'shift';
    shifts: CecShiftBlock[];
    /** The whole-day rollup — null on a single-shift read. */
    day: { summary: ShiftKpiReport; sums: CecSums } | null;
}

export interface WorkOrderScrap {
    id: number;
    reason: ScrapReason;
    quantity: string;
    cost_impact: string;
    notes: string | null;
}

export interface WorkOrder {
    id: number;
    item: Item;
    bom_id: number;
    routing_id: number | null;
    warehouse: Warehouse;
    scheduled_date: string | null;
    quantity_planned: string;
    quantity_completed: string;
    material_cost: string;
    status: WorkOrderStatus;
    materials: WorkOrderMaterial[];
    scraps: WorkOrderScrap[];
    released_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface MrpNetRequirement {
    item_id: number;
    sku: string | null;
    name: string | null;
    gross_required: string;
    on_hand: string;
    net_required: string;
}

export interface CapacityDayLoad {
    date: string;
    load_hours: string;
    capacity_hours: string | null;
    utilization_percent: number | null;
    overloaded: boolean;
}

export interface CapacityWorkCenterLoad {
    work_center_id: number;
    work_center_code: string;
    work_center_name: string;
    capacity_hours_per_day: string | null;
    days: CapacityDayLoad[];
}

export type SubcontractOrderStatus = 'draft' | 'materials_sent' | 'completed';

export interface SubcontractOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_sent: string;
}

export interface SubcontractOrder {
    id: number;
    vendor: Vendor;
    item: Item;
    bom_id: number;
    warehouse: Warehouse;
    quantity_planned: string;
    quantity_received: string;
    materials_cost: string;
    service_cost: string;
    total_cost: string;
    status: SubcontractOrderStatus;
    materials: SubcontractOrderMaterial[];
    materials_sent_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export type ReworkOrderStatus = 'draft' | 'released' | 'completed';

export interface ReworkOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_issued: string;
}

export interface ReworkOrder {
    id: number;
    item: Item;
    source_work_order_id: number | null;
    bom_id: number | null;
    warehouse: Warehouse;
    quantity_input: string;
    quantity_recovered: string;
    material_cost: string;
    labor_cost: string;
    total_cost: string;
    status: ReworkOrderStatus;
    materials: ReworkOrderMaterial[];
    released_at: string | null;
    completed_at: string | null;
    created_at: string;
}

// ---------------------------------------------------------------------------
// Lot/barcode traceability (Phase 6, docs/archive/SHIFT-REDESIGN-TRACEABILITY-DESIGN.md).
// Everything below is served ONLY when config('production.traceability_enabled')
// is true — with the flag off these endpoints 404 and no UI references them.
// Shapes mirror the design doc's data model verbatim.
// ---------------------------------------------------------------------------

/**
 * ALL SIX cases of the backend's MaterialBagStatus, and it must stay all six.
 *
 * It named four until 27-Aug-2026, while the enum had carried `waiting_qc` and
 * `rejected_qc` since the owner-confirmed arrival flow: a bag's identity and
 * label are born at arrival, and QC disposition is what releases it to
 * production. Every consumer typed against the short union was therefore told
 * a bag could not be in the two states it most often IS while it sits on the
 * receiving dock — which is how a status tag rendered blank on the new bag
 * screens rather than saying "waiting QC".
 *
 * A map keyed by this type is exhaustive BY CONSTRUCTION, so widening it is
 * what makes the compiler name the places that had quietly stopped being
 * total. Do not narrow it again to silence one.
 */
export type MaterialBagStatus =
    | 'in_store'
    | 'in_day_bin'
    | 'consumed'
    | 'returned'
    | 'waiting_qc'
    | 'rejected_qc';

/** The id/name/sku slice the day-bin aggregates embed. */
export interface ItemLite {
    id: number;
    name: string;
    sku: string;
}

/** One supplier lot on a GRN (design: material_lots) — MaterialLotResource. */
export interface MaterialLot {
    id: number;
    grn_id: number | null;
    goods_receipt_note_line_id?: number | null;
    item?: Item;
    supplier_lot_no: string | null;
    received_date: string | null;
    bag_count: number;
    /** Nominal kg per bag — decimal string. */
    bag_weight_kg: string | null;
    total_received_kg: string | null;
    bags?: MaterialBag[];
    /**
     * ======================= THE FINANCE-ONLY RATE GROUP =======================
     *
     * These four keys are ABSENT — not null — unless the reading login holds
     * finance.view or finance.manage. What a supplier charged per kg is
     * Owner and Accounts territory; the store reads this same register for
     * bags and kilograms and never sees them.
     *
     * THE OPTIONALITY IS LOAD-BEARING AND MUST NOT BE FLATTENED. `undefined`
     * means "you are not shown rates"; `null` means "this lot genuinely has
     * no known rate" — the honest state of opening stock, which was never
     * bought through a goods receipt. Typing these as plain `string | null`
     * still compiles and silently merges the two, which would make a store
     * login's blank indistinguishable from a lot that cost nothing.
     */
    /** The ORIGINAL goods-receipt rate. Provisional, but never rewritten. */
    receipt_rate_per_kg?: string | null;
    /** Where that original rate came from; null means unknown, not zero. */
    rate_source?: string | null;
    /**
     * The best rate known today — the latest appended cost version (invoice,
     * landed cost, correction), falling back to the receipt rate when
     * nothing has been appended. So it EQUALS the receipt rate on an
     * unrevised lot rather than going null.
     */
    current_rate_per_kg?: string | null;
    /** True once anything other than the original receipt rate was recorded. */
    has_revisions?: boolean;
    notes: string | null;
    created_at: string;
}

/** One physical bag with its own barcode (design: material_bags) — MaterialBagResource. */
export interface MaterialBag {
    id: number;
    material_lot_id: number;
    /** Supplier's barcode when scannable, else app-generated LOT{lot}-B{seq}. */
    barcode: string;
    original_kg: string;
    remaining_kg: string;
    status: MaterialBagStatus;
    current_warehouse_id: number | null;
    day_bin_work_center_id: number | null;
    /** Lot context for pick lists / scan feedback (present when the API loads it). */
    lot?: MaterialLot;
    created_at?: string | null;
}

export type DayBinMovementType = 'load' | 'return' | 'count';

/** One row of the per-machine day-bin ledger (design: day_bin_movements). */
export interface DayBinMovement {
    id: number;
    work_center_id: number;
    item_id: number;
    item?: Item;
    /** The shift SEGMENT the movement belongs to; null when logged outside a running batch. */
    shift_production_entry_id: number | null;
    type: DayBinMovementType;
    material_bag_id: number | null;
    material_bag?: MaterialBag | null;
    quantity_kg: string;
    recorded_by?: { id: number; name: string } | null;
    recorded_at: string;
}

/**
 * THE SCAN ACKNOWLEDGEMENT VOCABULARY — the four words the server accepts
 * when it refuses a scan into a machine that is still estimated to hold
 * material.
 *
 * These literals are validated server-side against a fixed list
 * (FactoryDayBinService::ACK_REASONS); anything else is a 422. They are a
 * union type and not `string` precisely so a typo is a compile error here
 * rather than a rejected scan on the floor.
 *
 * NOTE WHAT IS NOT HERE: a weight. The gate never asks anyone to weigh
 * anything — this factory does no routine day-bin weighing and no surface
 * built on this type may introduce one.
 */
export type BalanceAckReason = 'confirm_extra' | 'spill' | 'return_to_store' | 'correction';

/**
 * The four words in the language the floor speaks, in the order the owner
 * gave them. The server's refusal sentence ends with the RAW tokens (it
 * names the vocabulary it will accept); this list is what the operator
 * actually picks from.
 */
export const BALANCE_ACK_REASON_OPTIONS: { value: BalanceAckReason; label: string }[] = [
    { value: 'confirm_extra', label: 'Extra material confirmed' },
    { value: 'spill', label: 'Spill' },
    { value: 'return_to_store', label: 'Returned to store' },
    { value: 'correction', label: 'Needs correction' },
];

/** A bag currently sitting at the machine, as the day-bin aggregate reports it. */
export interface DayBinLoadedBag {
    id: number;
    barcode: string;
    remaining_kg: string;
    lot: { id: number; supplier_lot_no: string | null } | null;
}

/**
 * Per-material live state of one machine's day bin —
 * GET /production/work-centers/{id}/day-bin (one row per item with any
 * movements on the machine; balance from the backend ledger).
 */
export interface DayBinMaterialState {
    item: ItemLite;
    balance_kg: string;
    /** Bags physically at this machine right now, oldest first. */
    loaded_bags: DayBinLoadedBag[];
}

export interface DayBinState {
    materials: DayBinMaterialState[];
}

/**
 * Per-entry (segment) consumption summary — the backend-computed formula
 * `actual_consumed_kg = opening + Σ loaded − closing − Σ returned` that
 * PRE-FILLS the dedicated Resin/MB rows in Complete Batch. The supervisor
 * confirms or corrects; manual entry always stays authoritative.
 * GET /production/shift-production-entries/{id}/day-bin.
 */
export interface EntryDayBinMaterialSummary {
    item: ItemLite;
    opening_kg: string;
    loaded_kg: string;
    returned_kg: string;
    /** Latest `count` movement for the segment; null when nothing counted yet. */
    closing_kg: string | null;
    /** Null until a closing count exists (not computable ≠ zero). */
    consumption_kg: string | null;
}

export interface EntryDayBinSummary {
    /** False = the floor ignored scanning for this segment — prefill nothing. */
    has_movements: boolean;
    materials: EntryDayBinMaterialSummary[];
}

// ---------------------------------------------------------------------------
// Read-only production reports (feat/reports-wave). Pure aggregation over
// existing data; field names deliberately reuse the ProductionMetrics /
// ConsumptionVariance keys above so the backend resources and this file can
// never drift apart on naming. Response envelopes (shared rule with the
// backend): production = {data: {rows, totals}}; reconciliation =
// {data: {rows}}; traceability = {data: {lots}}.
// ---------------------------------------------------------------------------

export interface ReportShiftRef {
    id: number;
    name: string;
}

export interface ReportWorkCenterRef {
    id: number;
    code: string;
    name: string;
}

/** One completed entry (machine/shift/item grain) on the production report. */
export interface ProductionReportRow {
    entry_id: number;
    production_date: string;
    shift: ReportShiftRef;
    work_center: ReportWorkCenterRef;
    item: ItemLite;
    batch_number: string | null;
    running_hours: string | null;
    /** ROUND(3600/CT × cavities × hours / pack) — formula dictionary row 22. */
    expected_boxes: number | null;
    actual_boxes: number | null;
    expected_pieces: string | null;
    actual_pieces: string | null;
    good_production_kg: string | null;
    rejection_kg_production: string | null;
    rejection_kg_qc: string | null;
    /**
     * How many pieces quality REVIEWED — "so we know the analysis better
     * tomorrow" (owner). Optional and NOT SERVED YET: ProductionReportService
     * carries the quality rejection in kilograms (`rejection_kg_qc` above,
     * which the gate now writes) but no piece counts, so this renders an em
     * dash until the backend adds it. Declared here so the column exists and
     * fills itself in the moment it does.
     */
    qc_reviewed_pieces?: string | null;
    lumps_kg: string;
    /** actual_boxes / expected_boxes × 100 — formula dictionary row 24. */
    efficiency_pct: number | null;
    /** Same band set as ProductionMetrics, `over_standard` included. */
    efficiency_band?: 'ok' | 'watch' | 'investigate' | 'over_standard' | null;
}

export interface ProductionReportTotals {
    entries: number;
    /** Null when no row carried the figure (plain column sums otherwise). */
    expected_boxes: number | null;
    actual_boxes: number | null;
    actual_pieces: string;
    good_production_kg: string;
    rejection_kg_production: string;
    rejection_kg_qc: string;
    /** Day sum of the per-row reviewed count; not served yet, same as the row. */
    qc_reviewed_pieces?: string | null;
    lumps_kg: string;
    /**
     * Σ actual_boxes / Σ expected_boxes × 100 — RATIO OF SUMS (formula
     * dictionary row 24, WB2 totals row), never the average of the per-row
     * percentages. Null when Σ expected_boxes is 0 or unknown.
     */
    efficiency_pct: number | null;
}

export interface ProductionReport {
    date: string;
    rows: ProductionReportRow[];
    totals: ProductionReportTotals;
    /**
     * What `efficiency_pct` divides by, IF the server names it (Phase 7,
     * WS-C — the honesty key Shift Summary carries as `efficiency_basis`).
     * NOT SERVED by ProductionReportService today; the page then reads the
     * report's own documented definition (Σ actual pieces ÷ Σ expected
     * pieces at the standard cycle time — see productionReports.ts) and
     * labels the column "vs standard". Optional so a backend that never
     * sends it and one that starts to are both read correctly.
     */
    efficiency_basis?: string | null;
}

/**
 * One completed entry on the reconciliation report, served worst-first by
 * |reconciliation_unaccounted_kg|. Field names = the ProductionMetrics
 * reconciliation block.
 */
export interface ReconciliationReportRow {
    entry_id: number;
    production_date: string;
    shift: ReportShiftRef;
    work_center: ReportWorkCenterRef;
    item: ItemLite;
    batch_number: string | null;
    issued_kg: string;
    good_production_kg: string | null;
    confirmed_rejection_kg: string | null;
    lumps_kg: string;
    /** issued − good − confirmed_rejection − lumps; null when not computable. */
    reconciliation_unaccounted_kg: string | null;
    unaccounted_band?: 'ok' | 'investigate' | null;
    /** Issue-vs-norm variance rides along (ConsumptionVariance naming). */
    variance_pct: number | null;
    variance_band?: 'ok' | 'watch' | 'investigate' | null;
}

/**
 * Traceability report (lot → bags → fed machine/segment destinations),
 * envelope {data: {lots}}. Served ONLY when
 * config('production.traceability_enabled') is on — same flag/middleware as
 * the day-bin routes; the UI tab is equally invisible with the flag off.
 */
export interface TraceabilityReportFeed {
    machine: ReportWorkCenterRef;
    /** Null for a load recorded outside any batch window — machine still shows. */
    segment: { id: number; batch_number: string | null } | null;
    /** Σ kg this bag loaded into this machine/segment destination. */
    loaded_kg: string;
    /** Number of load movements collapsed into this destination. */
    loads: number;
}

export interface TraceabilityReportBag {
    id: number;
    barcode: string;
    status: MaterialBagStatus;
    original_kg: string;
    remaining_kg: string;
    fed: TraceabilityReportFeed[];
}

export interface TraceabilityReportRow {
    id: number;
    supplier_lot_no: string | null;
    received_date: string | null;
    item: ItemLite;
    bag_count: number;
    total_received_kg: string | null;
    bags: TraceabilityReportBag[];
}

/**
 * The production-readiness gate (backend ProductReadinessService). A
 * `blocking` entry refuses Start Batch; a `warning` is shown but allows it.
 * Severity per check is backend config, so the same check can move between
 * the two lists as the factory's master data fills in.
 */
export interface ReadinessFinding {
    code: string;
    label: string;
    detail: string;
}

export interface ProductReadiness {
    ready: boolean;
    blocking: ReadinessFinding[];
    warnings: ReadinessFinding[];
    summary: string | null;
    /**
     * A LOCAL- fixture product: it exists in this database and in no Tally
     * company. Its missing Tally GUID is intentional, so the `tally_item`
     * check is skipped rather than failed — and the UI must say plainly what
     * that costs (no voucher will be posted) instead of staying silent.
     * Optional so an older backend that doesn't send it reads as false.
     */
    is_local_fixture?: boolean;
}

/** Expected consumption for one recipe line, in that material's own unit. */
export interface EstimatedMaterial {
    item_id: number;
    name: string;
    uom: string | null;
    quantity: string;
    is_mass: boolean;
}

/**
 * The Start Batch estimation card. Every figure is null when its inputs are
 * missing — nothing here invents a number, because the point of showing it
 * before the run is that the supervisor can object to a wrong one.
 */
export interface BatchEstimation {
    planned_hours: string | null;
    standard_cycle_time: string | null;
    standard_cavities: number | null;
    active_cavities: number | null;
    expected_cycles: number | null;
    expected_pieces: number | null;
    expected_kg: string | null;
    nos_per_tray: number | null;
    nos_per_box: number | null;
    nos_per_pouch: number | null;
    expected_trays: number | null;
    expected_boxes: number | null;
    expected_pouches: number | null;
    expected_materials: EstimatedMaterial[];
    recipe_source: string | null;
    packaging_mode?: string | null;
    /**
     * WHICH FORMULA this estimate is (Phase 5.5, P5.5-03) — the version a
     * batch started from this preview will be stamped with
     * (production_v3_unified: cycles floored before cavities multiply). Optional
     * only for a backend that predates the key.
     */
    calculation_version?: string | null;
    /** Always false here: the preview knows no downtime yet (planned hours straight in) and says so. */
    downtime_netted?: boolean;
}

/**
 * MASTERBATCH DOSING — grams of colour per bottle, the factory's own master
 * figure (amber: 0.25 g/bottle, given 31 Jul). Shape mirrors
 * MasterbatchDosingService::describe() field for field.
 *
 * An EMPTY list from the endpoint is a real answer, not a failure: it means no
 * dosing is set for that (masterbatch, product) pair, so the completion screen
 * prefills nothing and says nothing. There is deliberately no zero row — zero
 * would assert that a colour needs no masterbatch, which nobody has said.
 */
export interface MasterbatchDosing {
    id: number;
    masterbatch_item: { id: number; name: string };
    /** Null = applies to every product that uses this masterbatch. */
    product_item: { id: number; name: string } | null;
    /** Human label for the above, e.g. "factory-wide". */
    scope: string;
    /** GRAMS per bottle — 4dp decimal string, e.g. "0.2500". Never a number. */
    grams_per_bottle: string;
    /** Where the figure came from, free text — "factory, 31 Jul". */
    note: string | null;
    set_by: string | null;
    set_at: string | null;
    /**
     * Echo of the bottle count `suggested_kg` was quoted against; null when
     * the caller sent none.
     */
    bottles: number | null;
    /**
     * kg for `bottles`, computed by ProductionCalculationEngine::masterbatchKg.
     * Null when no count was sent — a kg with no bottle count behind it is a
     * figure nobody can check.
     */
    suggested_kg: string | null;
}

/**
 * A material the completion screen should arrive with ALREADY CHOSEN, plus the
 * per-bottle figure behind it — the answer to "which resin / which colour, and
 * how many grams a bottle", so the supervisor confirms a figure instead of
 * assembling one. `reason` is the sentence the screen prints under the row, so
 * a pre-selection is never unexplained ("matched to the bottle's colour").
 *
 * EVERY KEY IS OPTIONAL, deliberately. This block is served by the preview
 * endpoint (`suggested_resin` / `suggested_masterbatch`); a backend that does
 * not send it yet must leave the screen working, and one that names the
 * material as a bare `item_id` rather than an `item` object must still
 * pre-select rather than silently fall back to an empty picker — which is the
 * exact defect this shape exists to end. Read through ONE function
 * (`readSuggestion` in ShiftProductionEntryPage) so a wire-shape correction is
 * a one-line change.
 */
export interface SuggestedMaterial {
    item?: { id: number; name?: string | null; sku?: string | null } | null;
    /** Alternative to `item` — the material by id alone. */
    item_id?: number | null;
    /** GRAMS per bottle. A decimal string on the wire; a number is accepted. */
    grams_per_bottle?: string | number | null;
    /** Why this material, in the supervisor's words. */
    reason?: string | null;
}

/**
 * ONE PACKING MATERIAL this run consumes — the carton, the tray, the film that
 * wraps a carton's contents, the tape that seals it — already resolved to a
 * Tally item by the factory's own mapping, carrying the per-unit figure the
 * completion drawer multiplies by ITS OWN carton and tray counts. The drawer
 * does the multiplying, live off what the supervisor is typing; this block
 * only says WHICH item and HOW MUCH PER CARTON (or per tray).
 *
 * THE KEYS BELOW ARE THE ONES THE BACKEND ACTUALLY SERVES, and nothing else.
 * They are the literal return shape of
 * `PackingMaterialSuggestionService::forStandard()` — nine keys, every one of
 * them always present. Earlier drafts of this interface also declared a dozen
 * plausible alternative spellings (`uom`, `per_unit`, `grams_each`,
 * `warehouse`, `label`, `spec_provenance` …) as a hedge against a wire name
 * changing. None of them is ever sent, and a type that lists fields the server
 * has no column for is a lie a reader has no way to catch: it invites the next
 * person to write `row.warehouse` and wonder why the store is never set. If a
 * key here is ever renamed on the backend, rename it here — the compiler will
 * point at the one read site.
 *
 * They stay OPTIONAL only so that a backend deployed before this block existed
 * leaves the drawer working untouched; the reading is done in ONE function
 * (`readPackingSuggestions` in ShiftProductionEntryPage) so that stays true.
 *
 * `item` NULL IS A REAL ANSWER, not a failure. The factory master carries spec
 * strings its Tally item list has no match for ("300ML ROUND", "750*610", the
 * "500ML IFF" that names two live tray items), and those mappings are still the
 * owner's to make. The drawer then names the spec and counts nothing — never a
 * zero line, which would assert the factory packs that product in nothing, and
 * never a block on completing the batch.
 */
export interface SuggestedPackingMaterial {
    /**
     * Which material this is, in the backend's own vocabulary:
     * `carton` | `tray` | `pouch_film` | `tape` (PackingMaterialMapping::KIND_*).
     * Note `pouch_film`, not `film` — the drawer normalises it.
     */
    kind?: string | null;
    /** The master's spec string this row was matched from — "170ML", "750*610". */
    spec?: string | null;
    /**
     * The Tally item the mapping resolved, or NULL when the factory has not
     * answered that spec yet. `name` is the empty string if the item row has
     * gone; the drawer treats that as unnamed and falls back to the catalogue.
     */
    item?: { id: number; name?: string | null } | null;
    /**
     * What the factor is counted against — "per_carton" / "per_tray" as
     * PackingMaterialMapping::KIND_BASIS states it. Film is per CARTON (one
     * film wraps a carton's contents — the owner's answer, 31 Jul), and so is
     * tape.
     */
    basis?: string | null;
    /** The word for that count in the arithmetic line — "cartons", "trays". */
    quantity_basis?: string | null;
    /**
     * HOW MUCH one basis unit takes, as the mapping states it: "1" for a
     * carton or a tray, the tape's metres per box, the film's GRAMS PER PIECE.
     * NULL when the mapping carries the item but not the dose yet.
     *
     * Read together with `factor_unit`, which is the only thing that says
     * which of those three it is — a factor of 120 with factor_unit "g" and
     * unit "kg" is 0.12 kg a carton, not 120 of anything.
     */
    factor?: string | number | null;
    /** The QUANTITY's unit — "nos", "kg", "m". Never the factor's. */
    unit?: string | null;
    /** The factor's own unit — "nos", "g", "m". Never the quantity's. */
    factor_unit?: string | null;
    /**
     * Why this item, in the supervisor's words — and the only place a spec's
     * provenance arrives: `inferredNote()` appends "spec inferred from row N"
     * to this sentence rather than sending a separate provenance object.
     */
    reason?: string | null;
}

export interface BatchPreview {
    readiness: ProductReadiness;
    estimation: BatchEstimation;
    /**
     * Every packing material this run consumes, one entry per material, each
     * already matched to a Tally item by the factory's mapping. Optional and
     * empty-tolerant: a backend that predates the mapping sends nothing and the
     * completion drawer shows no packing section at all, exactly as before.
     *
     * `suggested_packing` is the ONE key BatchPreviewController serves, from
     * PackingMaterialSuggestionService::forStandard, alongside suggested_resin
     * and suggested_masterbatch. It had three speculative aliases here that the
     * server never sends; they are gone, because a fallback chain reading keys
     * that cannot arrive hides a rename instead of surfacing it.
     */
    suggested_packing?: SuggestedPackingMaterial[] | null;
    /**
     * The resin and the masterbatch this run should consume, pre-chosen by the
     * backend (the colour column is the authority for the masterbatch, never
     * the item's name). Optional: absent on a backend that predates them, and
     * the screen falls back to what it can prove from the recipe, the day bin
     * and the catalogue.
     */
    suggested_resin?: SuggestedMaterial | null;
    suggested_masterbatch?: SuggestedMaterial | null;
    /** The resolved variant, or null when the product offers a choice. */
    standard:
        | (Omit<StandardVariant, 'packagings' | 'label'> & {
              label: string;
              unresolved_reason: string | null;
              /**
               * Packaging-MATERIAL specs from the factory master's three
               * right-hand columns (CARTON / TRAY / POUCH). Free text, e.g.
               * "750*610" — a pouch film in millimetres, not a count. Shown for
               * reference only; nothing is ever computed from them, which is
               * why they are strings rather than numbers.
               */
              carton_spec: string | null;
              tray_spec: string | null;
              pouch_spec: string | null;
          })
        | null;
    variants: StandardVariant[];
    packaging: { id: number; mode: string; label: string; nos_per_box: number | null } | null;
    /**
     * THE RUN'S OWN VERDICT (Phase 5.5 fix loop, P5.5-06): what a batch
     * started from this preview, as it stands, would be missing —
     * ProductVariantService::runStatus for the resolved standard and
     * packaging, the SAME rule startBatch freezes into the entry's
     * `configuration_gaps`, so the Start modal and Completed Today can never
     * contradict each other about one run. `grain` says whose verdict it is
     * ('run' once a packaging is resolved; 'standard' — the union — while
     * the packaging is still to be chosen). Null while the STANDARD is still
     * to be chosen (that question comes first). Optional only for a backend
     * that predates the key; the per-variant statuses are unchanged beside
     * it. startBatchChoices() reads this first.
     */
    configuration_status?: ConfigurationCompleteness | null;
    /**
     * The APPROVED machine-product configuration governing this run, when the
     * chosen machine has one. Its figures outrank the product standard — the
     * estimation above is already computed from them; this block exists so the
     * screen can say so instead of leaving the numbers unexplained.
     */
    configuration?: {
        id: number;
        default_cycle_time: string | null;
        default_cavities: number | null;
        unit_weight_grams: string | null;
        colour: string | null;
        /**
         * The mould this configuration runs (Phase 5.5, P5.5-01) — read
         * from the resolved configuration's own mould, so Start Batch can
         * say it. Null when the configuration names no mould; optional
         * because a backend that predates the key sends nothing.
         */
        mould?: { id: number; code: string | null; name: string | null } | null;
    } | null;
    warnings: StandardWarning[];
}

export interface VoucherPreviewLine {
    side: 'consumption' | 'production';
    item: string | null;
    quantity: string | number | null;
    uom: string | null;
    godown: string | null;
    problems: string[];
}

/**
 * A figure this voucher deliberately does NOT carry, with the factory's own
 * reason for holding it back — mirrors the `withheld` entries
 * TallySyncService::buildBatchVoucherPayload puts on the payload.
 *
 * Two kinds today, both the owner's own rulings:
 *  - `'tape'` — calculated from the exact metres-per-carton standard, not
 *    posted while Tally counts the tape in Nos ("Do not post tape until its
 *    Tally unit is metres or there is an exact approved conversion").
 *  - `'scrap'` — the rejected pieces and lumps this batch made, stated and not
 *    posted ("Do not create a Tally scrap-output line until the owner confirms
 *    whether rejected pieces and lumps are physically kept as stock or
 *    discarded").
 *
 * HELD BACK ON PURPOSE IS NOT BROKEN: none of these ever makes `postable`
 * false. They are shown so the accountant learns the figure is known, counted
 * and waiting on an answer — an absence would explain nothing.
 */
export interface VoucherWithheldLine {
    /**
     * The two kinds that exist — PackingVoucherLines::WITHHELD_TAPE and
     * ::WITHHELD_SCRAP, and nothing else. Left as a closed union on purpose: a
     * third kind added on the server should fail this typecheck rather than
     * arrive on the approval screen labelled with its own raw slug.
     */
    kind: 'tape' | 'scrap';
    item: string | null;
    quantity: string;
    unit: string;
    reason: string;
}

export interface VoucherPreview {
    voucher: Record<string, unknown>;
    lines: VoucherPreviewLine[];
    /**
     * What is calculated but deliberately not posted. Optional: a backend that
     * predates the withheld lines sends no key at all.
     */
    withheld?: VoucherWithheldLine[];
    /**
     * The quiet sentences — true, worth reading once before signing, nothing to
     * do about them. Never blockers.
     */
    notes?: string[];
    problems: string[];
    postable: boolean;
}

// ---------------------------------------------------------------------
// Configurable production
// ---------------------------------------------------------------------

/**
 * Machine + Product + Mould + Colour — the controlling production standard.
 * Only an `approved` configuration, effective today, drives a batch; drafts
 * exist to be reviewed and are invisible to the shop floor.
 */
export type ConfigurationStatus = 'draft' | 'approved' | 'inactive';

export interface ProductionConfiguration {
    id: number;
    /**
     * `code` and `name` are both `whenLoaded` on the resource, so a caller
     * that did not eager-load the machine gets an id and nothing else. The
     * floor calls machines by code (MC-04) and the office by name (Machine
     * 4); a list carrying only one of the two is unreadable to half the
     * factory, so readers show both when both arrive.
     */
    work_center: { id: number; code?: string | null; name?: string };
    item: { id: number; name?: string; sku?: string };
    mold: { id: number; name?: string } | null;
    colour: string | null;
    unit_weight_grams: string | null;
    default_cycle_time: string | null;
    cycle_time_min: string | null;
    cycle_time_max: string | null;
    default_cavities: number | null;
    cavities_min: number | null;
    cavities_max: number | null;
    permitted_cavities: number[] | null;
    bom_id: number | null;
    status: ConfigurationStatus;
    effective_from: string | null;
    effective_to: string | null;
    approved_at: string | null;
    approved_by?: string | null;
    source: string | null;
    source_reference: string | null;
    /** The factory's own wording, e.g. "To Confirm" — shown, never acted on. */
    /** TRUE once archived (soft-deleted). Optional — absent = not serialised. */
    is_archived?: boolean;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Optional: absent on a backend that predates the wiring, and the row
     * actions then offer nothing rather than invent permission. `delete:
     * null` on an index row means UNDETERMINED — the confirm asks.
     */
    can?: ConfigurationAbilities | null;
    confirmation_status: string | null;
    notes: string | null;
}

export interface DowntimeReason {
    id: number;
    code: string;
    category: string | null;
    description: string;
    planning_type: 'planned' | 'unplanned';
    reduces_runtime: boolean;
    requires_note: boolean;
    selectable_at_start: boolean;
    is_active: boolean;
    confirmation_status: string | null;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

/**
 * One downtime event against a batch — planned at Start Batch or logged with
 * the completion. `reason` rides along when loaded (it is `whenLoaded` on the
 * resource, so treat it as possibly absent) and `minutes` is a decimal string.
 */
export interface ProductionDowntimeEvent {
    id: number;
    downtime_reason_id: number;
    reason?: DowntimeReason | null;
    minutes: string;
    is_planned: boolean;
    known_before_start: boolean;
    note: string | null;
    recorded_at: string | null;
}

export interface FactorySetting {
    id: number;
    key: string;
    value: string | null;
    typed_value: unknown;
    data_type: string;
    scope: string;
    label: string | null;
    description: string | null;
    confirmation_status: string | null;
    /** Whether any screen or rule reads this value; the rest are reference rows. */
    applied: boolean;
    is_active: boolean;
    effective_from: string | null;
    change_reason: string | null;
    changed_by?: string | null;
    updated_at: string | null;
}

/** One row's verdict from an import dry run. */
export interface ImportRowResult {
    row: number;
    action: 'create' | 'conflict' | 'rejected';
    reason: string | null;
    resolved: Record<string, unknown>;
    source_row: Record<string, unknown>;
    created_id?: number;
}

export interface ImportResult {
    dry_run: boolean;
    summary: { create: number; conflict: number; rejected: number };
    rows: ImportRowResult[];
}

/** The three ways a product reaches a box. The workspace filters on these. */
export type StandardPackagingMode = 'pouch' | 'tray' | 'direct_box';

/** A packaging option on a product standard: how pieces reach a box. */
export interface StandardPackaging {
    id: number;
    mode: StandardPackagingMode;
    label: string;
    nos_per_pouch: number | null;
    pouches_per_box: number | null;
    nos_per_tray: number | null;
    trays_per_box: number | null;
    nos_per_box: number | null;
    is_default: boolean;
    /**
     * The packing's OWN Tally identity (DEC-20260810-003): the item this
     * packing's production posts as. Null/absent = no identity of its own —
     * the run uses the product's item and the screen must say so ("using
     * product identity"), never guess. Optional because older payload shapes
     * (and the workspace's toArray) may omit or nest it differently.
     */
    tally_item?: PackagingTallyItem | null;
    /**
     * Whether the row can actually run a batch: pieces per box plus its
     * mode's inner count, both stated. A half-stated workbook row (item
     * 423's "120/pouch, boxes not stated") is display data — the picker
     * disables it, and the server refuses to resolve it either way.
     */
    is_complete: boolean;
    /**
     * Phase 5 (P5-06): whether this packing is CONFIGURED — counts stated
     * AND a real Tally item to post as (its own identity, else the
     * product's) — and, when it is not, which pieces are missing, in the
     * server's keys (`counts`, `tally_identity`, …). Additive: the batch
     * preview embeds it per packaging; the workspace's `toArray()` rows do
     * not carry it and derive the same answer locally by the same rule
     * (see productStandardsConfig.ts `packagingState`).
     */
    configuration_status?: ConfigurationCompleteness | null;
    /** TRUE once archived (soft-deleted). Optional — absent = not serialised. */
    is_archived?: boolean;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

/**
 * The Tally item a packing posts as. Three producers, three widths: the
 * packaging write sends `{id, name}`; the batch preview and the variants
 * endpoint send `{id, sku, name, guid}`; the workspace rows carry the
 * eager-loaded Item verbatim (`sku`, `tally_stock_item_guid`, …).
 * Everything past `id` and `name` is optional so a reader can show the SKU
 * when it has one and say nothing when it does not.
 */
export interface PackagingTallyItem {
    id: number;
    name: string;
    sku?: string | null;
    guid?: string | null;
    tally_stock_item_guid?: string | null;
}

/**
 * "Is this configured, and if not, what is missing?" — the server's verdict
 * on one packing, one standard or one product (ProductVariantService,
 * P5-02 / P5-06). `missing` holds the server's KEYS in the server's order —
 * `standard`, `cavities`, `unit_weight`, `cycle_time`, `packaging`, `counts`,
 * `tally_identity` — never sentences; the words a person reads are made from
 * them once, on the client (productStandardsConfig.ts), so an unknown key
 * still shows rather than vanishing.
 *
 * Two spellings of the verdict arrive and both are read: a packing or a
 * standard says `state: 'complete' | 'incomplete'`, the product says
 * `complete: boolean`. A packing's verdict also carries `ambiguity` — set
 * when the identity it resolves to has a NAME shared by more than one item.
 */
export interface ConfigurationCompleteness {
    complete?: boolean;
    state?: 'complete' | 'incomplete';
    missing: string[];
    ambiguity?: { shared_name_count: number } | null;
    /**
     * Whose verdict this is, on the preview's top-level block (Phase 5.5 fix
     * loop): 'run' — the ONE packaging the run resolved to (or no standard
     * to speak of), the same judgment startBatch freezes into the entry's
     * configuration_gaps; 'standard' — the standard's union over every
     * packaging, because the packaging is still to be chosen. Absent on the
     * per-variant / per-packaging blocks.
     */
    grain?: 'run' | 'standard';
}

/**
 * One product-level standard variant from the factory master: cavities +
 * weight + cycle time, with its packaging options. A product may have
 * several genuinely different variants; the supervisor picks one only when
 * there is more than one.
 */
export interface StandardVariant {
    id: number;
    label: string;
    cavities: number | null;
    unit_weight_grams: string | null;
    cycle_time: string | null;
    status: 'draft' | 'approved' | 'unresolved';
    packagings: StandardPackaging[];
    /** Phase 5 additive: the variant's own configured-or-not verdict, when the server sends it. */
    configuration_status?: ConfigurationCompleteness | null;
}

/** Advisory only — watch mode never blocks a start. */
export interface StandardWarning {
    code: string;
    message: string;
}

// ---------------------------------------------------------------------------
// The bin-bay AVAILABILITY read (GET /production/bin-bay/availability,
// traceability-gated like every shape above): the machine-scoped ledger
// balance with its source-lot layers, and the run's recipe priced against
// it. READ ONLY — the Bin Bay loading page and its write are gone
// (DEC-20260807-006); loads happen through the common input's bag scan.
// ---------------------------------------------------------------------------

/**
 * One load that fed the bin, oldest first — the bin's own FIFO (the order
 * material physically went in), which is not the store pick list's ordering
 * (lot received_date).
 */
export interface BinBayLayer {
    movement_id: number;
    material_bag_id: number | null;
    barcode: string | null;
    /** Ledger truth: the kg this load put in. */
    loaded_kg: string;
    /**
     * DERIVED, not recorded: the current balance allocated across layers
     * first-in-first-out, so what is left is attributed to the newest loads.
     * An estimate — the ledger never tracks which grain came out of which bag.
     */
    in_bin_kg: string;
    recorded_at: string | null;
    lot: { id: number; supplier_lot_no: string | null; received_date: string | null } | null;
}

/** What one bin bay holds of one material right now, and where it came from. */
export interface BinBayAvailability {
    work_center_id: number;
    item: { id: number; name: string; sku: string | null; uom: string | null } | null;
    /** The ledger balance — a weighed count re-anchors it. */
    available_kg: string;
    loaded_kg: string;
    /** Balance a count put above everything ever loaded; no lot can account for it. */
    unattributed_kg: string;
    layers: BinBayLayer[];
}

export interface BinBayRequirementComponent {
    item_id: number;
    name: string;
    sku: string | null;
    uom: string | null;
    /** Kg-based components are day-bin tracked; Nos consumables are not. */
    is_mass: boolean;
    /** Null when no piece count was given — a blank is honest, a zero is not. */
    expected_quantity: string | null;
    available_quantity: string;
    /** Null for non-mass components: they never sit in the bin, so "short" is meaningless. */
    shortage_quantity: string | null;
}

/** The product's active recipe priced out against what the bay actually holds. */
export interface BinBayRequirement {
    product_item_id: number;
    expected_pieces: number | null;
    /** Null = no active BOM; the components list is then empty rather than guessed. */
    recipe_source: 'bom' | null;
    components: BinBayRequirementComponent[];
}

export interface BinBayAvailabilityResponse {
    /** Null unless an item_id was asked for. */
    bin: BinBayAvailability | null;
    /** Null unless a product_item_id + expected_pieces pair was asked for. */
    requirement: BinBayRequirement | null;
}

/**
 * One row of the factory's imported product master — the workbook's
 * cavities/weight/cycle-time for a product, with the packaging modes it can be
 * packed in, attached to the Tally item it applies to.
 *
 * Distinct from ProductionConfiguration, which is a machine + product + mould
 * approval. A standard says what a product runs to WHEREVER it runs; a
 * configuration says that a specific machine has been approved to run it.
 */
export interface ProductionStandardRow {
    id: number;
    item: Item | null;
    source_product_name: string;
    cavities: number | null;
    unit_weight_grams: string | null;
    cycle_time: string | null;
    cycle_time_raw: string | null;
    carton_spec: string | null;
    tray_spec: string | null;
    pouch_spec: string | null;
    status: 'draft' | 'approved' | 'unresolved';
    unresolved_reason: string | null;
    source: string | null;
    source_reference: string | null;
    confirmation_status: string | null;
    packagings: StandardPackaging[];

    /**
     * WHERE each packing-material spec came from, keyed by the column it
     * describes — `carton_spec`, `tray_spec`, `pouch_spec`. A column ABSENT
     * from the map came from the workbook verbatim and needs no caveat.
     *
     * The workbook leaves these blank on some rows and the factory fills them
     * by knowing the family ("375ML KIDNEY packs in the same carton as the
     * 500ML KIDNEY"). The inference is recorded alongside the value rather
     * than inside it — writing "HM 30.5*49 (inferred)" into the string would
     * corrupt the value the packing-materials build is about to consume — so
     * this map is the only thing that can tell a guess from a stated figure.
     *
     * Optional: a backend that has not run the fill omits it, and every cell
     * then renders as plain text.
     */
    spec_provenance?: Partial<Record<StandardSpecColumn, StandardSpecProvenance>> | null;

    /**
     * Who attached the Tally item to this standard, and when.
     *
     * `item_attached_by` is a users FK, so it serialises as a bare id unless
     * the relation is eager-loaded — hence the union. The page shows a NAME or
     * nothing: "attached by 7" is worse than silence.
     */
    /**
     * TRUE when the standard has been ARCHIVED. It has no active flag of its
     * own — the model carries SoftDeletes and nothing else, so archiving IS
     * the soft delete, and the resource reports the fact rather than leaking
     * `deleted_at`. Optional: absent on a backend that does not serialise it,
     * and the status tag then says nothing rather than calling every row live.
     */
    is_archived?: boolean;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
    item_attached_by?: number | { id: number; name?: string | null } | null;
    item_attached_at?: string | null;
}

/** The three workbook columns an inference may ever fill. */
export type StandardSpecColumn = 'carton_spec' | 'tray_spec' | 'pouch_spec';

/**
 * One packing spec's origin, exactly as the backend stores it. `inferred` is
 * only ever true today — a stated value has no entry at all — but it is read
 * rather than assumed, so a later "confirmed by the factory" entry can be
 * recorded here without this page mislabelling it as a guess.
 */
export interface StandardSpecProvenance {
    inferred?: boolean;
    value?: string;
    /** The SL.NO. of the row the fill was taken from, e.g. "58". */
    from_source_reference?: string;
    /** That row's product name, e.g. "500ML KIDNEY". */
    from_product?: string;
    /** The evidence, in a sentence — shown to the person reading the cell. */
    reason?: string;
    inferred_by?: string;
    inferred_on?: string;
}

/**
 * A Tally item the backend offers as a possible match for an unattached
 * standard, scored by NAME SIMILARITY ONLY (the import's --diagnose
 * machinery: PHP `similar_text` over normalised names, plus whether the
 * leading size token agrees). It is a shortlist to read, never a decision —
 * the person attaching makes that.
 */
export interface StandardItemCandidate {
    /** The item's id — the value the attach endpoint takes. */
    id: number;
    name: string;
    sku?: string | null;
    /** 0–100. Higher is a closer NAME, not a more correct product. */
    score: number;
    /** The leading size figure agrees, e.g. "500" in both "500ML ROUND" names. */
    same_size?: boolean;
    /**
     * Another standard of this same product name already points at this item.
     *
     * Not a refusal — one mould covers every colour of its bottle, so two
     * variants legitimately share an item. It is the one thing name-similarity
     * cannot know, and it is how a person tells "this is the sibling variant"
     * from "I am about to pick the wrong bottle".
     */
    attached_to_same_product?: boolean;
}

// ---------------------------------------------------------------------------
// The PRODUCT STANDARDS WORKSPACE — the same standards rows, plus everything
// the single product-configuration screen answers about them.
//
// Every field below is computed by the backend, and the gap sentences are the
// Start Batch gate's OWN strings (ProductReadinessService::SENTENCES, which
// both the gate and this workspace now read). The page must never paraphrase
// them: a supervisor told one thing here and another when the batch is
// refused stops believing both screens.
// ---------------------------------------------------------------------------

/** Which products a view shows. Production-ready is the default the server applies. */
export type ProductStandardsView = 'ready' | 'incomplete' | 'all';

/**
 * The six things that can stand between a product and a shift, in the gate's
 * own vocabulary. Same keys as ProductReadinessService's findings.
 */
export type ProductStandardGapKey =
    | 'weight'
    | 'cycle_time'
    | 'cavities'
    | 'packing'
    | 'colour'
    | 'tally_item';

/**
 * Where a person goes to close a gap. Four destinations for six gaps — the
 * three run figures are one edit.
 *
 * The workspace resolves each of these to a real control; a gap whose target
 * it cannot honour would be the vague note this screen exists to abolish.
 */
export type ProductStandardFixTarget =
    | 'standard_edit'
    | 'packing_edit'
    | 'item_colour'
    | 'attach_item';

/** One numbered gap: what is missing, what it costs, and where to fix it. */
export interface ProductStandardGap {
    /** 1..n within the row — numbered so it can be worked through and reported back on. */
    number: number;
    key: ProductStandardGapKey;
    label: string;
    sentence: string;
    fix_target: ProductStandardFixTarget;
}

/**
 * The product's Tally identity and the exact consequence of not having one.
 *
 * `sentence` is null when the item is attached AND Tally carries it. When it
 * is present it is stated verbatim — production is not blocked by this, and a
 * screen that implied otherwise would refuse work the floor is allowed to do.
 */
export interface ProductStandardTallyIdentity {
    attached: boolean;
    guid_present: boolean;
    sentence: string | null;
}

/**
 * The recipe a run of this product will consume, named read-only.
 *
 * Recipes are ITEM-level and Bills of Material owns them. This workspace
 * shows which one is in force and links to it; it never copies or edits one,
 * because a second editing surface for one master is how two versions of a
 * recipe start disagreeing.
 */
export interface ProductStandardActiveRecipe {
    id: number;
    name: string;
    version: string | null;
}

/**
 * A machine exception as it rides ON the workspace row — the slim projection
 * the collapsed table needs.
 *
 * The expanded row re-reads the same exceptions from
 * `standards/{standard}/machine-exceptions`, which returns the full
 * ProductionConfiguration resource the write actions need. Both come from the
 * same service in the same machine order.
 */
export interface StandardMachineException {
    id: number;
    work_center: { id: number; code: string | null; name: string | null };
    status: ConfigurationStatus;
    colour: string | null;
    mold: { id: number; name: string | null } | null;
    effective_from: string | null;
    effective_to: string | null;
    default_cycle_time: string | null;
    default_cavities: number | null;
    unit_weight_grams: string | null;
    /** The lifecycle `can` block (DEC-20260817-002); absent = not wired yet. */
    can?: ConfigurationAbilities | null;
}

/**
 * One workspace row: every key the standards index already returned, plus the
 * workspace's own answers.
 *
 * It extends ProductionStandardRow rather than restating it because the
 * backend builds the row with `$standard->toArray() + [...]` — the additions
 * are additions, and anything reading the old shape keeps working.
 */
export interface ProductStandardsWorkspaceRow extends ProductionStandardRow {
    /** Empty exactly when `ready` is true. */
    gaps: ProductStandardGap[];
    ready: boolean;
    /** The packaging option a run would actually resolve to, if any. */
    resolved_packaging_id: number | null;
    machine_exceptions: StandardMachineException[];
    active_recipe: ProductStandardActiveRecipe | null;
    tally: ProductStandardTallyIdentity;
}

/**
 * The chip numbers.
 *
 * Counted over the FILTERED set minus the view, so clicking Incomplete never
 * rewrites the number that told you to click it.
 */
export interface ProductStandardsSummary {
    ready: number;
    incomplete: number;
    all: number;
}

// ---------------------------------------------------------------------------
// PHASE 5 — the product / SKU configuration surfaces (P5-02, P5-03).
//
// One logical product = one items row; its variants are the production
// standards, each with its packagings, and EACH PACKAGING carries its own
// Tally identity (DEC-20260810-003). These two reads answer "is this product
// fully configured?" and "which configurations still need a person?" — and
// the only writes either leads to are the EXISTING ones (the packaging PUT
// with an item_id, the standard's attach-item, the item's SKU): a human
// LINKS an existing Tally item; the ERP never invents one, and never picks
// one on a person's behalf.
// ---------------------------------------------------------------------------

/** The five packing counts, as the review surface carries them per packaging. */
export interface PackagingCounts {
    nos_per_pouch?: number | null;
    pouches_per_box?: number | null;
    nos_per_tray?: number | null;
    trays_per_box?: number | null;
    nos_per_box?: number | null;
}

/** The product an item IS, with its provisional-SKU flag (P5-02). */
export interface ProductVariantsItem {
    id: number;
    sku: string;
    name: string;
    guid: string | null;
    /**
     * TRUE while the SKU is the name-derived one the Tally pull seeded and
     * no person has set it. Never says what the SKU SHOULD be — the SKU
     * format programme is the owner's, and this screen only marks the rows
     * still waiting on it.
     */
    sku_provisional: boolean;
}

/** One packaging as the variants endpoint describes it — counts, identity, verdict. */
export interface ProductVariantPackaging extends PackagingCounts {
    id: number;
    mode: StandardPackagingMode;
    label: string;
    is_default: boolean;
    /** Runnable on its counts alone — the older flag the Start Batch picker disables on. */
    is_complete: boolean;
    /** The packing's OWN identity, or null when it posts as the product's item. */
    tally_item: PackagingTallyItem | null;
    /** The item it WILL post as — its own, else the product's (DEC-20260810-003); null when neither exists. */
    resolved_tally_item: PackagingTallyItem | null;
    uses_product_identity: boolean;
    state: 'complete' | 'incomplete';
    missing: string[];
    /**
     * Set when the identity this packing resolves to has a NAME carried by
     * more than one items row, so the name alone cannot say which — the
     * review surface lists it as `packaging_ambiguous` and a person chooses.
     */
    ambiguity: { shared_name_count: number } | null;
    /** The same verdict under the key every other surface reads. */
    configuration_status: ConfigurationCompleteness;
}

export interface ProductVariantStandard {
    id: number;
    label: string;
    source_product_name: string;
    cavities: number | null;
    unit_weight_grams: string | null;
    cycle_time: string | null;
    status: 'draft' | 'approved' | 'unresolved';
    unresolved_reason: string | null;
    packagings: ProductVariantPackaging[];
    /** The standard's own figures + its packing + its product's identity, plus every word its packagings say. */
    configuration_status: ConfigurationCompleteness;
}

/** GET production/products/{item}/variants */
export interface ProductVariants {
    item: ProductVariantsItem;
    standards: ProductVariantStandard[];
    configuration_status: ConfigurationCompleteness;
}

/**
 * The three kinds of row the review surface lists — a packaging with no
 * Tally identity of its own, a packaging whose identity name is shared by
 * more than one item, and an item still on its provisional SKU. A closed
 * union on purpose: a fourth kind added on the server should fail this
 * typecheck rather than arrive on the panel labelled with its raw slug.
 */
export type ConfigurationReviewKind =
    /**
     * DEC-20260821-001: this packing carries a Tally identity of its OWN
     * naming a DIFFERENT item from the product it sits under, which makes it
     * a separate finished product. EVIDENCE, not a refusal — the row already
     * exists and may already have posted vouchers. Nothing on the screen
     * fixes it, so it offers no Link and no candidate (`separate_product`).
     */
    | 'packaging_separate_product'
    | 'packaging_no_identity'
    | 'packaging_ambiguous'
    | 'item_provisional_sku';

/**
 * A Tally item offered as a possible identity — matched by exact /
 * normalised NAME, from Tally-pulled rows only (never a local fixture). A
 * shortlist to read, never a decision: nothing is written until a person
 * picks one.
 */
export interface ConfigurationReviewCandidate {
    id: number;
    sku: string | null;
    name: string;
    guid: string | null;
}

/**
 * Where a review row's fix goes — which EXISTING endpoint closes it:
 * the packaging's own item_id (PATCH packagings/{packaging}/identity —
 * identity only, never a count), the product's item (POST attach-item), or
 * the item's SKU (PUT inventory/items). `name_ambiguity` is ADVISORY: the
 * identity's NAME is carried by more than one catalogue row and Tally
 * matches by name, so no link clears it — a catalogue duplicate (Q43).
 * Optional: a backend that predates the key leaves the panel to infer it
 * from the row.
 */
export type ConfigurationReviewFixTarget =
    | 'packaging_item'
    | 'attach_item'
    | 'item_sku'
    | 'name_ambiguity'
    /**
     * ADVISORY and NON-MUTATING (DEC-20260821-001). No endpoint on this
     * screen closes it: the answer is a Tally stock item in the catalogue —
     * which only the masters pull puts there — plus a production standard of
     * its own, and the packing configured under THAT product. Never a link,
     * and never a "clear the identity": the review is advisory and rewrites
     * neither the current configuration nor posted history. The stored
     * identity is the CURRENT configuration's evidence — not the only record
     * of what already posted, which lives on the completed run's frozen
     * `finished_item_id` and in the queued voucher's own payload and event
     * trail, none of which this screen touches.
     */
    | 'separate_product';

/** One thing a person has to look at (P5-03). */
export interface ConfigurationReviewRow {
    kind: ConfigurationReviewKind;
    /** The standard the packaging belongs to; null on an item-only row. */
    standard: { id: number; product: string } | null;
    /**
     * The packaging concerned. Null on an item-only row — and on a
     * `packaging_no_identity` row that is about the STANDARD itself (its
     * product's identity is missing and no packing inherits it), whose fix
     * is attach-item rather than a packaging link.
     */
    packaging: { id: number; mode: StandardPackagingMode; counts: PackagingCounts | null } | null;
    /**
     * The item the row is ABOUT: the identity the packing resolves to today
     * (its own, else the product's — null when it resolves to nothing), or
     * the provisional-SKU item itself.
     *
     * On `packaging_separate_product` ONLY it is the packing's own stored
     * identity, and it may name an ARCHIVED catalogue row — the finding is
     * about the stored column, and a retired item row does not unset it.
     */
    item: { id: number; sku: string | null; name: string } | null;
    /**
     * `packaging_separate_product` ONLY — the product the packing currently
     * sits under, beside `item` (the different thing it posts as). The reader
     * needs both ends of the relation to see the conflict. Absent on every
     * kind that predates DEC-20260821-001, whose payloads are unchanged.
     */
    product_item?: { id: number; sku: string | null; name: string } | null;
    /** The server's keys for what is missing — same vocabulary as ConfigurationCompleteness.missing. */
    missing: string[];
    ambiguity?: { shared_name_count: number } | null;
    candidates: ConfigurationReviewCandidate[];
    fix_target?: ConfigurationReviewFixTarget;
}

/** GET production/configuration/review */
export interface ConfigurationReview {
    rows: ConfigurationReviewRow[];
}

// ---------------------------------------------------------------------------
// The FACTORY DAY BIN — the central place raw material sits after it leaves
// the store and before a machine runs.
//
// It is simply a WAREHOUSE, which is why there are no balances of its own
// here: `quantity_kg` is the ordinary stock balance for (material, day-bin
// warehouse). Loading it is the existing store → warehouse stock transfer,
// and consumption at batch completion reduces it because every consumption
// line already carries its own warehouse.
//
// Distinct from DayBinState / DayBinMovement above, which are the optional
// PER-MACHINE bag-level ledger (the barcode bin bay). This is the simple
// central figure that is always visible without picking a machine.
// ---------------------------------------------------------------------------

/**
 * Registered bags of one material still HOLDING material (remaining_kg > 0) —
 * a bag someone has already poured part of counts here with whatever is
 * actually left in it, not with its original weight. Column on the summary
 * row below; see FactoryDayBinService::rawMaterialSummary.
 */
export interface FactoryDayBinUnopenedBags {
    count: number;
    /** Their summed remaining kg — 4dp decimal string. Named `kg` on the wire. */
    kg: string;
}

/**
 * One material's current balance in the factory day bin — the ordinary stock
 * balance for (material, day-bin warehouse), since the bin IS a warehouse.
 */
export interface FactoryDayBinMaterial {
    /**
     * Optional because the backend serves it through `whenLoaded('item')`,
     * which drops the key rather than sending null. Both readers that build
     * these rows eager-load it, so in practice it is always there — but
     * `item_id` is what a row is identified by.
     */
    item?: Item;
    item_id: number;
    /** 4dp decimal string. The item's own `uom` says what the unit is. */
    quantity_kg: string;
    /**
     * The wrapped StockBalance's weighted-average purchase rate — served only
     * to finance.view/manage eyes (FC-06); the key is ABSENT, never null, for
     * everyone else. Presence is the server's ruling.
     */
    average_cost?: string;
}

/**
 * The owner's one-look row per RAW MATERIAL (kg-uom items only — bottles and
 * caps count in Nos and never appear): what is in the bin, what is still in
 * the store, and how many bags are still holding material.
 *
 * A SEPARATE top-level array from `materials`, not merged into it: its row set
 * is "kg items with a balance in the bin OR the store", so it can both omit a
 * non-kg material sitting in the bin and include a material the bin has none
 * of. The page unions the two by `item_id` for exactly that reason.
 */
export interface FactoryDayBinSummaryRow {
    item: Item;
    item_id: number;
    /** The bin warehouse's own balance — 4dp decimal string. */
    bin_kg: string;
    /** Summed balance across the Tally-linked godowns other than the bin. */
    store_kg: string;
    unopened_bags: FactoryDayBinUnopenedBags;
}

/**
 * One load INTO the day-bin warehouse today — a transfer_in stock movement
 * landing in the bin, newest first.
 */
export interface FactoryDayBinLoadRow {
    /** stock_movements.id. */
    id: number;
    /**
     * ISO8601 stock_movements.movement_date — when it physically went in.
     * Nullable on the wire, and `dayjs(null)` would quietly read as "now",
     * so every reader must guard it.
     */
    time: string | null;
    /** Served through `whenLoaded('item')` — see FactoryDayBinMaterial.item. */
    item?: Item;
    quantity_kg: string;
    /**
     * The scanned bag's barcode, parsed back out of the fixed reference a bag
     * load stamps; null for manual (no-barcode) loads.
     */
    bag_barcode: string | null;
    /**
     * Who loaded it — the authenticated user's NAME, already resolved. A
     * plain string, not an object: the id is deliberately not exposed here.
     */
    user: string | null;
    /** The movement's free-text reference ("Day bin load — bag …", or the form's). */
    reference: string | null;
}

/**
 * GET /production/factory-day-bin. `warehouse: null` means nobody has named
 * the day-bin warehouse yet — a normal state, not an error: every screen then
 * behaves exactly as it did before the day bin existed.
 *
 * All three arrays are always present. They come back EMPTY (never absent)
 * while no bin is configured, and an empty `summary`/`todays_loads` on a
 * configured bin simply means nobody has loaded it yet today.
 */
export interface FactoryDayBin {
    warehouse: Warehouse | null;
    materials: FactoryDayBinMaterial[];
    summary: FactoryDayBinSummaryRow[];
    todays_loads: FactoryDayBinLoadRow[];
}

/**
 * One material's ESTIMATED REMAINING IN THE COMMON RESIN INPUT, from
 * GET /production/machine-resin. Mirrors CommonResinMaterialResource field
 * for field.
 *
 * THERE IS NO MACHINE FIELD, and its absence is the point. The owner's
 * correction (2-Aug): the factory has ONE COMMON resin input point serving
 * every machine, a bag is never assigned or scanned to a machine, and a
 * per-machine balance was a number with no physical referent. This type
 * REPLACED the machine-keyed pair (MachineResinEstimate +
 * MachineResinMaterial) rather than nulling their machine field, so nothing
 * can keep rendering a dimension that no longer exists.
 *
 * Every figure is a 4dp decimal STRING, never a number — the same way the
 * rest of the shift engine speaks about kg, so a JSON parse cannot quietly
 * restate a stock quantity.
 *
 * WHAT THE WINDOW IS. `loaded_kg` is every load of this material into the
 * common input, whatever machine the row does or does not name (historical
 * rows carry one; rows written since the correction do not — the material
 * entered the factory's one input either way). `consumed_kg` counts only the
 * calculated consumption recorded AT OR AFTER the first such load — material
 * burnt before anyone recorded a load came out of an input nobody was
 * logging and is deliberately not subtracted. So the honest sentence for a
 * screen is "counted from the first load of this material", never "all time".
 */
export interface CommonResinMaterial {
    /**
     * Always present — the resource builds it with `ItemResource::make()`
     * directly, NOT through `whenLoaded`, so unlike FactoryDayBinMaterial's
     * `item` this one is never absent.
     */
    item: Item;
    item_id: number;
    /** Every load of this material into the common input. */
    loaded_kg: string;
    /**
     * The CURRENT calculated consumption of this material ACROSS ALL
     * MACHINES. A correction replaces its predecessor rather than adding to
     * it (the amendment deletes the entry's consumption rows before
     * re-booking), so this figure never double-counts a corrected batch.
     */
    consumed_kg: string;
    /**
     * loaded − consumed. CAN BE NEGATIVE and is served that way: consumption
     * is derived from output rather than weighed out, so a negative figure
     * means the factory ran on material nobody recorded loading — the one
     * thing on this read worth acting on, and the exact thing a clamp at zero
     * would erase. Show it, never hide it.
     *
     * IT LAGS THE FLOOR. Consumption is booked at batch completion, so
     * material an in-flight batch has already melted is not yet subtracted.
     * Every screen printing it must say so.
     */
    estimated_remaining_kg: string;
    /**
     * null = never loaded. The backend omits any material with no load at
     * all, so in practice this is always set; it stays nullable because the
     * resource declares it so.
     *
     * AN EMPTY ARRAY of these means "NOTHING HAS BEEN LOADED YET", not "the
     * input is empty": the backend answers with no rows at all when there are
     * no Load movements anywhere. A screen must say so in those words — an
     * empty estimate is a missing baseline, never a zero balance.
     */
    last_load_at: string | null;
}

/**
 * One choice in the Day Bin page's raw-material picker, from
 * GET /production/factory-day-bin/raw-materials — every ACTIVE kg-uom item.
 *
 * Deliberately NOT an `Item`: the backend sends a slim four-field projection
 * with the display string already built as `label`, so nothing here needs
 * `itemLabel()` (and passing this shape to it would silently render blanks —
 * every field `itemLabel` reads is optional, so it type-checks).
 */
export interface RawMaterialOption {
    id: number;
    /** The item's name, ready to show. */
    label: string;
    uom: string;
    /** Current store kg — 4dp decimal string. */
    store_kg: string;
}

/**
 * POST /production/day-bin/load-bag — the Shift Floor's "Load Material"
 * scan: one bag's kg move store → the internal WIP warehouse and enter the
 * COMMON RESIN INPUT.
 *
 * NO MACHINE IS NAMED (owner's correction, 2-Aug: the factory has one common
 * resin input point and a bag is never assigned or scanned to a machine). In
 * the books the stock simply changed location — Tally still sees one godown,
 * there is no warehouse per machine. Mirrors FactoryDayBinController::loadBag's
 * response verbatim.
 */
export interface FactoryDayBinLoadResult {
    /** Post-load bag state (remaining_kg already reduced; lot.item loaded). */
    bag: MaterialBag;
    /** The day bin's row for this material — quantity_kg is the NEW balance. */
    day_bin: FactoryDayBinMaterial;
    /**
     * The movement row the load just wrote, echoed back so the floor can
     * confirm what was recorded without a second read. It names NO machine —
     * `work_center_id` is written null on this path.
     */
    movement?: DayBinMovement;
}

/**
 * A FINISHED CARTON — one physical box of a completed batch's packed output,
 * carrying a permanent printed barcode. Generated once per batch (idempotent),
 * scanned at dispatch. Mirrors FinishedCartonResource.
 */
export interface FinishedCarton {
    id: number;
    /** The printed code: {batch_number}-C01, -C02, … Permanent. */
    carton_no: string;
    item?: Item;
    /** Pieces in this box — the last box of a run is usually a partial. */
    pieces: string;
    is_partial: boolean;
    status: 'in_stock' | 'dispatched';
    delivery_id: number | null;
    /**
     * NET weight in kg: pieces × the run's resolved unit weight — the same
     * figure the server computes every stored kilogram from. Null when the
     * run resolved no weight. There is deliberately no gross weight: it needs
     * the empty carton's tare, which exists nowhere in the data (pending
     * owner question Q15) — a missing figure is omitted, never invented.
     */
    net_weight_kg?: string | null;
    /**
     * Customer/PO for a batch made against a sales order. Blank-capable and
     * blank today: the schema has no batch→sales-order linkage (cartons meet
     * an order only at dispatch scan), so the server always sends null. The
     * label renders it only when a real linkage one day fills it.
     */
    sales_order?: { customer: string | null; order_no: string | null } | null;
    /**
     * The batch's quality and approval truth (DEC-20260807-013). The sticker
     * on the box is permanent — this block is the system's answer at scan
     * time. 'quality_rejected' renders LOUD and the server also refuses the
     * box at dispatch; 'pending' ships as today (open owner question Q27)
     * but must show; packed_nos is gross, qc_approved_nos the netted figure
     * once QC has counted (null before).
     */
    quality?: {
        entry_status: string | null;
        verdict: 'quality_rejected' | 'approved' | 'pending';
        packed_nos: string;
        qc_approved_nos: string | null;
        qc_rejected_nos: number | null;
    };
    /** The traceability spine: which batch this physical box came from. */
    batch?: {
        shift_production_entry_id: number;
        batch_number: string | null;
        production_date: string | null;
        machine: string | null;
        shift: string | null;
        /** The run's box size — pack variants differ by exactly this figure. */
        nos_per_box?: string | null;
    };
    /**
     * Present on the LABEL reads only (generate/reprint —
     * FinishedCartonLabelResource): the batch's completion date in factory
     * wall-clock and its shift, for the printed label (DEC-20260810-001).
     * The public scan lookup NEVER carries this key — the same decision
     * freezes that response, so don't "fix" its absence there.
     */
    completion?: {
        completed_on: string | null;
        shift: string | null;
    };
    created_at: string | null;
}

/**
 * One lot line of the internal trace's day-bin attribution — the CALCULATED
 * bin-held-these-lots claim, never a bag→batch identity (FC-01). rate_per_kg
 * is FC-06 material: it exists on this tier only and must not be rendered
 * anywhere outside the carton-trace surface.
 */
export interface CartonTraceLot {
    material: string | null;
    supplier_lot_no: string | null;
    grn_reference: string | null;
    inward_date: string | null;
    rate_per_kg: string | null;
    rate_source: string;
    loaded_kg: string;
}

/**
 * The INTERNAL carton trace tier (DEC-20260810-001), mirroring
 * CartonInternalTraceResource. Served only to logins holding
 * carton-trace.view — Owner (Administrator), Plant Manager, Accounts.
 */
export interface CartonInternalTrace {
    carton: FinishedCarton;
    completion: {
        completed_at: string | null;
        completed_on: string | null;
        shift: string | null;
    };
    day_bin_attribution: {
        /** The sentence this attribution must never be shown without. */
        basis: string;
        reason: string | null;
        window: {
            production_date: string;
            shift: string;
            timezone: string;
            from: string;
            until: string;
        } | null;
        lots: CartonTraceLot[];
        unattributed_loaded_kg: string;
        /**
         * Which ledger the window's kilograms came from — the day bin's own
         * loads, and what the store issued. A cross-reference between the two
         * blocks, never a merge of them.
         *
         * OPTIONAL ON PURPOSE: the server omits it on the degraded branch
         * (a batch that no longer resolves a shift and production date has no
         * window to total), which is the same branch that leaves `window`
         * null. It is present on every trace that has a window.
         */
        sources?: {
            day_bin_loaded_kg: string;
            store_issued_kg: string;
        };
    };
    /**
     * THE STORE-ISSUE LEDGER'S OWN BLOCK, under its own sentence.
     *
     * Since DEC-20260817-001 material reaches production as a store issue
     * into Production/WIP, not as a day-bin load. Those lots are NOT listed
     * under `day_bin_attribution`: that block's `basis` is owner-fixed
     * (DEC-20260810-001) and says the common day bin HELD these lots, which
     * is not true of a lot issued straight from the store. Two blocks, each
     * under words that are true of it — and `basis` here must be rendered
     * beside these rows exactly as it is there.
     */
    store_issue_attribution: {
        basis: string;
        reason: string | null;
        window: {
            production_date: string;
            shift: string;
            timezone: string;
            from: string;
            until: string;
        } | null;
        lots: CartonTraceLot[];
        unattributed_issued_kg: string;
    };
    /**
     * BagCostAllocationService::summary(withDetail) — totals, the
     * accounting-allocation `basis` sentence, and the pool rates behind
     * them (this tier is rate-bearing by the owner's word).
     */
    costing: {
        as_of: string;
        reason: string | null;
        allocation_run: number | null;
        material_cost_total: string | null;
        resin_cost: string | null;
        other_cost: string | null;
        cost_per_accepted_unit: string | null;
        accepted_quantity: string | null;
        basis: string;
        allocations?: Array<{
            item_id: number;
            item_name: string | null;
            pool_rate: string | null;
            quantity: string;
            amount: string | null;
            rate_source: string;
            sentence: string;
        }>;
        other_lines?: Array<Record<string, unknown>>;
    };
}

// ------------------------------------------------------ production request --

/**
 * WHERE A PRODUCTION REQUEST STANDS. None of these is a batch (invariant 2):
 * `in_progress` is a PERSON saying they picked the job up, never the ERP
 * noticing a shift entry, and nothing in this flow creates, starts or cancels
 * a batch.
 *
 * The queue read (`GET production/requests`) returns the OPEN ones only —
 * queued and in_progress. `produced` and `cancelled` exist on the type because
 * a lifecycle action returns the row it just moved.
 */
export type ProductionRequestStatus = 'queued' | 'in_progress' | 'produced' | 'cancelled';

/** WHY the floor is being asked — the order's identity, not its lines or totals. */
export interface ProductionRequestOrderRef {
    id: number;
    document_number: string;
    customer_name: string | null;
}

/**
 * What the floor may do with this row, as `ProductionRequestService::abilities`
 * decided it. The buttons read these; they never re-derive the state machine.
 *
 * PERMISSION IS A SECOND, SEPARATE GATE. `cancel` is two-sided
 * (production.manage OR inventory.manage — the route's OR group), while
 * `start` and `reorder` are the floor's alone (production.manage). A true here
 * means the DOCUMENT allows it, never that this login does.
 */
export interface ProductionRequestAbilities {
    start: boolean;
    cancel: boolean;
    reorder: boolean;
}

/**
 * One production request — the two-sided piece of paper the store raises and
 * the floor runs.
 *
 * FC-06: no rate, no amount, no vendor. It is about pieces and priority, so it
 * needs no finance standing to read and grants none.
 */
export interface ProductionRequest {
    id: number;
    /** "PR-{id}" — what the store and the floor quote at each other. */
    request_number: string;
    /** Dense and 1-based, rewritten wholesale by reorder(). */
    priority: number;
    status: ProductionRequestStatus;
    /** `name` is Tally's wire key; `display_name` is the ERP's own label, null when nobody set one. */
    item: { id: number; sku: string | null; name: string | null; display_name?: string | null } | null;
    /** Decimal string, 4dp — the same precision the order line and its holds carry. */
    quantity: string;
    sales_order_line_id: number;
    sales_order: ProductionRequestOrderRef | null;
    requested_by: number | null;
    requested_at: string | null;
    cancelled_reason: string | null;
    can: ProductionRequestAbilities;
}

/**
 * WHEN THE FACTORY COULD HAVE IT — the planning block on a queue row, exactly
 * as FulfilmentPlanningService computed it.
 *
 * NEVER PERSISTED (S11). There is no ETA column in this build: a stored date
 * is already wrong the moment somebody reorders the queue.
 */
export interface ProductionQueuePlanning {
    /**
     * FREE FINISHED GOODS for this product. ITEM-level, not line-level — it is
     * the same figure for every request against the same product, so a grouped
     * screen must take it ONCE and must never sum it across sub-rows.
     */
    free: string | null;
    /** How many open requests sit in front of this one. Per-request, not per-product. */
    queued_ahead: number | null;
    capacity_per_shift: number | null;
    shifts_needed: number | null;
    estimated_ready_date: string | null;
    /**
     * The factory cannot date this row — and the refusal CASCADES (S12): a
     * product with no usable standard costs every job behind it its date too.
     * A group containing one of these cannot be dated as a group.
     */
    cannot_estimate: boolean;
    /** The same tokens the planning dashboard prints, widened the same way. */
    reason: CannotEstimateReason | (string & {}) | null;
}

/** The order's identity plus its expected date — sales.view/manage only (the floor-visibility owner question). */
export interface ProductionQueueOrderRef extends ProductionRequestOrderRef {
    /**
     * Present only for a login that may read it. Deliberately NOT called a
     * promised date: Sales labels this field "Expected Date" everywhere it is
     * authored and validates it only as after_or_equal:order_date (the floor-visibility owner question).
     */
    expected_date?: string | null;
}

/**
 * One row of GET /production/queue — a production request with the demand
 * behind it and the date in front of it.
 *
 * THREE KEYS ARE OPTIONAL AND THE REASON IS PERMISSION, NOT ABSENCE OF DATA
 * (the floor-visibility owner question). The route is OR-gated so both desks can open the queue; the figures
 * it joins on belong to other modules and each is gated by the module that
 * owns it. `planning` and `ordered`/`delivered` are OMITTED — not nulled —
 * for a caller who may not read them.
 *
 * THE DISTINCTION MATTERS AND IS EASY TO LOSE. `planning === undefined` means
 * "not yours to read", so the column should not be drawn at all;
 * `planning.cannot_estimate === true` means "nobody knows", which is a real
 * factory state and must be printed as the refusal it is. Decide COLUMN
 * PRESENCE from whether the key exists, never per-cell from a falsy check —
 * `row.planning?.cannot_estimate` is `undefined` for the ungated caller and
 * would render an em-dash that reads as "no date yet".
 */
export interface ProductionQueueRow extends Omit<ProductionRequest, 'sales_order'> {
    sales_order: ProductionQueueOrderRef | null;
    /** What the customer ordered — inventory.* or sales.* only (the floor-visibility owner question). */
    ordered?: string | null;
    /** What has already gone out against the line — inventory.* or sales.* only (the floor-visibility owner question). */
    delivered?: string | null;
    /** inventory.view/manage only (the floor-visibility owner question). */
    planning?: ProductionQueuePlanning;
}

/** What the dates on a queue read were computed against. */
/**
 * What the dates on a queue read were computed against — the SAME basis the
 * planning dashboard prints, because it is the same walk. Aliased rather than
 * re-declared so the two screens cannot drift into describing one computation
 * two ways.
 */
export type ProductionQueueBasis = FulfilmentPlanningBasis;

export interface ProductionQueueRead {
    data: ProductionQueueRow[];
    basis: ProductionQueueBasis;
}
