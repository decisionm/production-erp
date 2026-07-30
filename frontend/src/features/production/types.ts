import type { Employee } from '@/features/hrms/types';
import type { Item, Warehouse } from '@/features/inventory/types';
import type { Vendor } from '@/features/procurement/types';

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
}

export interface Shift {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    is_active: boolean;
}

export type BatchStatus = 'in_progress' | 'completed';
export type ShiftProductionEntryStatus =
    | 'pending'
    | 'pm_approved'
    | 'accountant_approved'
    | 'approved'
    | 'rejected'
    | 'synced'
    | 'failed';
export type ShiftScrapType = 'rejected_finished_good' | 'lumps';

export interface ShiftMaterialConsumption {
    id: number;
    item: Item;
    warehouse: Warehouse;
    quantity_issued_kg: string;
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
    /** actual_boxes / expected_boxes × 100 rounded 1dp — null when expected_boxes null/0. */
    efficiency_pct: number | null;
    efficiency_band?: 'ok' | 'watch' | 'investigate' | null;
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
    /** True when the configured hard gate refuses accountant approval. */
    blocks_approval?: boolean;
}

export interface ShiftProductionEntry {
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
    batch_number: string | null;
    quantity_produced: string | null;
    quantity_produced_kg: string | null;
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
    /** Snapshot from the item at Start Batch. */
    standard_cavities: number | null;
    /** Editable; defaults to standard. */
    active_cavities: number | null;
    /** Entered at Complete Batch. */
    running_hours: string | null;
    /** Entered at/after completion. */
    qc_rejection_kg: string | null;
    material_consumptions: ShiftMaterialConsumption[];
    scraps: ShiftScrap[];
    /** Null when batch_status is not completed (no consumption yet). */
    variance: ConsumptionVariance | null;
    /**
     * Null for non-completed batches — the frontend duplicates the expected_*
     * formula for the live running screen; backend is authoritative after
     * completion.
     */
    metrics: ProductionMetrics | null;
    /** Latest Tally sync error — present only when status is "failed". */
    sync_error?: string | null;
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
    rejection_percent: number | null;
    machines_running: number;
    machines_down: number;
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
// Lot/barcode traceability (Phase 6, SHIFT-REDESIGN-TRACEABILITY-DESIGN.md).
// Everything below is served ONLY when config('production.traceability_enabled')
// is true — with the flag off these endpoints 404 and no UI references them.
// Shapes mirror the design doc's data model verbatim.
// ---------------------------------------------------------------------------

export type MaterialBagStatus = 'in_store' | 'in_day_bin' | 'consumed' | 'returned';

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
    lumps_kg: string;
    /** actual_boxes / expected_boxes × 100 — formula dictionary row 24. */
    efficiency_pct: number | null;
    efficiency_band?: 'ok' | 'watch' | 'investigate' | null;
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
}

export interface BatchPreview {
    readiness: ProductReadiness;
    estimation: BatchEstimation;
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

export interface VoucherPreview {
    voucher: Record<string, unknown>;
    lines: VoucherPreviewLine[];
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
    work_center: { id: number; name?: string };
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

/** A packaging option on a product standard: how pieces reach a box. */
export interface StandardPackaging {
    id: number;
    mode: 'pouch' | 'tray' | 'direct_box';
    label: string;
    nos_per_pouch: number | null;
    pouches_per_box: number | null;
    nos_per_tray: number | null;
    trays_per_box: number | null;
    nos_per_box: number | null;
    is_default: boolean;
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
}

/** Advisory only — watch mode never blocks a start. */
export interface StandardWarning {
    code: string;
    message: string;
}

// ---------------------------------------------------------------------------
// The CENTRAL bin bay (/production/bin-bay/*, traceability-gated like every
// shape above). Material is loaded into a machine's day bin ONCE, at the bay
// — never re-declared inside a batch.
//
// A load is an inventory LOCATION movement: the material travels store →
// machine day bin and nothing else happens. It is NOT consumption (that is
// derived later, at batch completion, from the day-bin count) and it NEVER
// posts a Tally voucher.
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

/** Who loaded what into a bay, when, off which bag — newest first. */
export interface BinBayHistoryRow {
    id: number;
    recorded_at: string | null;
    quantity_kg: string;
    /** Loading is central: normally null, set only when a load is tied to a running segment. */
    shift_production_entry_id: number | null;
    item: { id: number; name: string; sku: string | null } | null;
    material_bag_id: number | null;
    barcode: string | null;
    lot: { id: number; supplier_lot_no: string | null } | null;
    loaded_by: { id: number; name: string } | null;
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
}
