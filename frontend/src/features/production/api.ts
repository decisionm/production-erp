import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    ProductionStandardRow,
    ProductStandardsSummary,
    ProductStandardsView,
    ProductStandardsWorkspaceRow,
    StandardItemCandidate,
    StandardPackagingMode,
    BalanceAckReason,
    BatchPreview,
    BinBayAvailabilityResponse,
    FinishedCarton,
    BinBayHistoryRow,
    Bom,
    DowntimeReason,
    FactorySetting,
    ImportResult,
    ProductionConfiguration,
    CapacityWorkCenterLoad,
    DayBinMovement,
    DayBinState,
    EntryDayBinSummary,
    FactoryDayBin,
    FactoryDayBinLoadResult,
    MachineDowntimeLog,
    CommonResinMaterial,
    MaterialBag,
    MaterialBagStatus,
    MasterbatchDosing,
    MaterialLot,
    Mold,
    MoldChangeLog,
    MoldStatus,
    MrpNetRequirement,
    PowerInterruptionLog,
    ProductionReport,
    RawMaterialOption,
    ReconciliationReportRow,
    ReworkOrder,
    Routing,
    ScrapReason,
    Shift,
    ShiftKpiReport,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    ShiftStockCount,
    ShiftSummary,
    SubcontractOrder,
    TraceabilityReportRow,
    VoucherPreview,
    WorkCenter,
    WorkOrder,
} from './types';

/**
 * THE ONE PLACE IN THE FACTORY, RESOLVED — never asked.
 *
 * There is one factory and one physical place inside it, and Tally's books
 * carry exactly one godown for it. So no floor screen may ask a supervisor
 * which store anything came out of or goes into: the answer is always the
 * same warehouse, and a screen that asks is asking a question the system
 * already knows the answer to.
 *
 * The rule is the backend's own, not a new one and not a name guess:
 * TallyGodownResolver::soleLinkedWarehouse() takes the warehouse Tally
 * actually knows when there is exactly one of them, and declines otherwise.
 * Mirroring it here means the id this frontend sends is the id the voucher
 * builder and the readiness gate would have chosen anyway, so the two cannot
 * disagree about where stock moved.
 *
 * `tally_guid` is set by exactly one code path — WarehouseService::
 * syncGodownsFromTally, which mirrors Tally's godown list 1:1 — so "the
 * warehouse with a tally_guid" is a fact from the books, not a local opinion.
 * Warehouses seeded for rehearsal (RM-STORE, WIP, FG-STORE) never carry one
 * and so can never be resolved into a real stock movement.
 *
 * Returns undefined when the answer is not certain — no Tally-linked
 * warehouse, or more than one. The caller must then STATE that in a plain
 * line with a link to where it is fixed, and never fall back to guessing
 * from warehouse names: a silent wrong answer here books resin into the
 * bottle store, and somebody has to find that later.
 *
 * @param excludeId a warehouse that cannot be the answer for this caller —
 *   the day bin on a store→bin transfer, which the endpoint refuses when
 *   from and to are the same warehouse.
 */
export interface FactoryStoreCandidate {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    tally_guid: string | null;
}

export function resolveFactoryStore<T extends FactoryStoreCandidate>(
    warehouses: T[] | undefined,
    excludeId?: number | null,
): T | undefined {
    const linked = (warehouses ?? []).filter(
        (warehouse) => warehouse.is_active && warehouse.tally_guid !== null && warehouse.id !== excludeId,
    );

    return linked.length === 1 ? linked[0] : undefined;
}

/** "SWAASHPET POLYMERS PVT LTD" as a floor-readable line: code — name. */
export function factoryStoreLabel(warehouse: FactoryStoreCandidate | undefined): string | null {
    return warehouse ? `${warehouse.code} — ${warehouse.name}` : null;
}

/**
 * @param active true = in-service machines only (what every production
 *   selector must pass), false = retired only, undefined = both.
 */
export async function listWorkCenters(active?: boolean): Promise<Paginated<WorkCenter>> {
    const { data } = await api.get<Paginated<WorkCenter>>('/production/work-centers', {
        params: active === undefined ? undefined : { active: active ? 1 : 0 },
    });
    return data;
}

/**
 * "Machine 1 (MC-01)" — the floor name plus the internal code, so a
 * supervisor and the office are naming the same machine.
 */
export function machineLabel(machine: Pick<WorkCenter, 'name' | 'code'>): string {
    return machine.code && machine.code !== machine.name ? `${machine.name} (${machine.code})` : machine.name;
}

/**
 * Everything the machine master can write — the same vocabulary as
 * StoreWorkCenterRequest and UpdateWorkCenterRequest, field for field.
 *
 * There used to be two write functions against this one endpoint: an identity
 * one (code/name/hours/active, from the retired Work Centers page) and a
 * "capability" one (cavities/cycle time, from Machine Setup). They wrote the
 * same PUT and neither could express a whole machine, so adding a machine with
 * its cavity range known meant saving it twice. One machine master, one
 * payload.
 *
 * `default_shift_hours` and `confirmation_status` are deliberately ABSENT.
 * Both columns exist and both ride the wire back, but nothing edits them — an
 * omitted key is untouched by the backend, which is how they stay exactly as
 * the workbook left them.
 *
 * Null means "no limit known" and never blocks anything; only a stated limit
 * constrains a configuration.
 */
export interface WorkCenterWritePayload {
    code: string;
    name: string;
    capacity_hours_per_day?: number | null;
    is_active?: boolean;
    capacity_class?: string | null;
    min_cavities?: number | null;
    max_cavities?: number | null;
    /** Explicit set for machines whose options are not a continuous range. */
    permitted_cavities?: number[] | null;
    cycle_time_min?: number | null;
    cycle_time_max?: number | null;
}

export async function createWorkCenter(payload: WorkCenterWritePayload): Promise<WorkCenter> {
    const { data } = await api.post<{ data: WorkCenter }>('/production/work-centers', payload);
    return data.data;
}

export async function updateWorkCenter(
    id: number,
    payload: Partial<WorkCenterWritePayload>,
): Promise<WorkCenter> {
    const { data } = await api.put<{ data: WorkCenter }>(`/production/work-centers/${id}`, payload);
    return data.data;
}

export async function listBoms(itemId?: number): Promise<Paginated<Bom>> {
    const { data } = await api.get<Paginated<Bom>>('/production/boms', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

export interface CreateBomPayload {
    item_id: number;
    name: string;
    version?: string;
    is_active?: boolean;
    lines: { component_item_id: number; quantity_per: number }[];
}

export async function createBom(payload: CreateBomPayload): Promise<Bom> {
    const { data } = await api.post<{ data: Bom }>('/production/boms', payload);
    return data.data;
}

export async function listRoutings(itemId?: number): Promise<Paginated<Routing>> {
    const { data } = await api.get<Paginated<Routing>>('/production/routings', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

export interface CreateRoutingPayload {
    item_id: number;
    name: string;
    is_active?: boolean;
    operations: { work_center_id: number; sequence: number; name: string; standard_time_minutes?: number }[];
}

export async function createRouting(payload: CreateRoutingPayload): Promise<Routing> {
    const { data } = await api.post<{ data: Routing }>('/production/routings', payload);
    return data.data;
}

export async function listWorkOrders(): Promise<Paginated<WorkOrder>> {
    const { data } = await api.get<Paginated<WorkOrder>>('/production/work-orders');
    return data;
}

export interface CreateWorkOrderPayload {
    item_id: number;
    bom_id?: number;
    routing_id?: number;
    warehouse_id: number;
    scheduled_date?: string;
    quantity_planned: number;
}

export async function createWorkOrder(payload: CreateWorkOrderPayload): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>('/production/work-orders', payload);
    return data.data;
}

export async function releaseWorkOrder(id: number): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>(`/production/work-orders/${id}/release`);
    return data.data;
}

export interface WorkOrderScrapEntry {
    scrap_reason_id: number;
    quantity: number;
    notes?: string;
}

export async function completeWorkOrder(
    id: number,
    quantityCompleted: number,
    batchNumber?: string,
    scrap?: WorkOrderScrapEntry[],
): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>(`/production/work-orders/${id}/complete`, {
        quantity_completed: quantityCompleted,
        batch_number: batchNumber,
        scrap,
    });
    return data.data;
}

export async function getMrpNetRequirements(itemId: number, quantity: number): Promise<MrpNetRequirement[]> {
    const { data } = await api.get<{ data: MrpNetRequirement[] }>('/production/mrp/net-requirements', {
        params: { item_id: itemId, quantity },
    });
    return data.data;
}

export async function getCapacityLoadReport(startDate: string, endDate: string): Promise<CapacityWorkCenterLoad[]> {
    const { data } = await api.get<{ data: CapacityWorkCenterLoad[] }>('/production/capacity/load-report', {
        params: { start_date: startDate, end_date: endDate },
    });
    return data.data;
}

export async function listSubcontractOrders(): Promise<Paginated<SubcontractOrder>> {
    const { data } = await api.get<Paginated<SubcontractOrder>>('/production/subcontract-orders');
    return data;
}

export interface CreateSubcontractOrderPayload {
    vendor_id: number;
    item_id: number;
    bom_id?: number;
    warehouse_id: number;
    quantity_planned: number;
}

export async function createSubcontractOrder(payload: CreateSubcontractOrderPayload): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>('/production/subcontract-orders', payload);
    return data.data;
}

export async function sendSubcontractOrderMaterials(id: number): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>(`/production/subcontract-orders/${id}/send-materials`);
    return data.data;
}

export async function receiveSubcontractOrder(
    id: number,
    payload: { quantity_received: number; service_cost: number },
): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>(`/production/subcontract-orders/${id}/receive`, payload);
    return data.data;
}

export async function listScrapReasons(): Promise<Paginated<ScrapReason>> {
    const { data } = await api.get<Paginated<ScrapReason>>('/production/scrap-reasons');
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllScrapReasons(): Promise<Paginated<ScrapReason>> {
    const { data } = await api.get<Paginated<ScrapReason>>('/production/scrap-reasons', { params: { per_page: 1000 } });
    return data;
}

export interface CreateScrapReasonPayload {
    code: string;
    name: string;
}

export async function createScrapReason(payload: CreateScrapReasonPayload): Promise<ScrapReason> {
    const { data } = await api.post<{ data: ScrapReason }>('/production/scrap-reasons', payload);
    return data.data;
}

export async function listShifts(): Promise<Paginated<Shift>> {
    const { data } = await api.get<Paginated<Shift>>('/production/shifts');
    return data;
}

export interface CreateShiftPayload {
    name: string;
    start_time: string;
    end_time: string;
}

export async function createShift(payload: CreateShiftPayload): Promise<Shift> {
    const { data } = await api.post<{ data: Shift }>('/production/shifts', payload);
    return data.data;
}

export async function listShiftProductionEntries(status?: ShiftProductionEntryStatus): Promise<Paginated<ShiftProductionEntry>> {
    const { data } = await api.get<Paginated<ShiftProductionEntry>>('/production/shift-production-entries', {
        params: status ? { status } : undefined,
    });
    return data;
}

/**
 * Every completed batch still waiting for its approval chain — the whole
 * pending list, not one page of it.
 *
 * WHY IT PAGES INSTEAD OF READING THE FIRST RESPONSE, the same reason the
 * quality queue does: the list is fixed at 20 per page and ordered by
 * production date descending, and the batches that need attention are exactly
 * the ones that have been waiting longest. A night shift running 22:00→06:00
 * files under YESTERDAY's production date, so at 06:45 its batches are neither
 * "today" nor near the top of a newest-first page — and they are precisely the
 * ones a supervisor is still doing paperwork on.
 *
 * `status=pending` is the server's own filter and already implies a completed
 * batch (ShiftProductionEntryService::paginate refuses to mix in a running
 * one), so the walk is bounded by the approval backlog rather than by all of
 * production history. `meta.last_page` bounds the loop; the hard cap is a
 * second bound so a malformed meta cannot spin, and it is the honest limit of
 * this read — a backlog deeper than 25 pages (500 batches awaiting approval)
 * would leave the oldest unlisted, which is itself a sign the chain has
 * stopped moving.
 */
export async function listPendingEntries(): Promise<ShiftProductionEntry[]> {
    const all: ShiftProductionEntry[] = [];
    let page = 1;
    let lastPage = 1;

    do {
        const { data } = await api.get<Paginated<ShiftProductionEntry>>('/production/shift-production-entries', {
            params: { status: 'pending', page },
        });
        all.push(...(data?.data ?? []));
        lastPage = data?.meta?.last_page ?? 1;
        page += 1;
    } while (page <= lastPage && page <= 25);

    return all;
}

/**
 * Every machine's currently-running batch across ALL shifts/dates, never
 * paginated — the authoritative source for the Shift Floor's machine state
 * (matches the backend's global one-in-progress-per-machine guard).
 */
export async function listActiveBatches(): Promise<{ data: ShiftProductionEntry[] }> {
    const { data } = await api.get<{ data: ShiftProductionEntry[] }>('/production/shift-production-entries/active');
    return data;
}

export interface StartBatchPayload {
    shift_id: number;
    work_center_id: number;
    item_id: number;
    // WHERE THE FINISHED BOTTLES LAND. Resolved, never asked — see
    // resolveFactoryStore above. Optional on the wire only for the case where
    // no Tally-linked warehouse can be resolved at all: the screen then states
    // that instead of showing a picker, and the server arbitrates (it still
    // validates the field whenever it is sent). It is NEVER the day bin —
    // that is the kg resin bin, and finished goods booked into it are a stock
    // correction somebody has to find later.
    warehouse_id?: number;
    production_date?: string;
    operator_id?: number;
    // Which factory product-standard variant and packaging this run uses.
    // Asked only when the product genuinely offers a choice.
    production_standard_id?: number;
    production_standard_packaging_id?: number;
    // Backend defaults active cavities to the item's standard at Start Batch;
    // sent when the supervisor overrides it up front (e.g. blocked cavity).
    // Complete Batch re-sends it, so a backend that ignores this still gets
    // the corrected value at completion.
    active_cavities?: number;
    // Which colour actually ran. Asked at Start Batch whenever the masters
    // don't already fix one (the factory workbook has no reliable colour
    // column), and snapshotted onto the entry. Never defaulted client-side.
    colour?: string;
    // Why this run is starting with less material in the machine's bin than
    // its recipe needs. Sent only when the supervisor explicitly waved the
    // shortage through — the server records it and refuses nothing, so the
    // tick-box in the dialog is the guard, not this field.
    material_shortage_override_reason?: string;
}

/**
 * One product the factory's standards cover, and the product name they cover
 * it under. The slim projection behind the Start Batch picker's split into
 * "Production ready" and "Unconfigured" — see the backend's
 * ProductionStandardController::coverage for why it isn't the full standards
 * read.
 */
export interface StandardCoverageRow {
    item_id: number;
    source_product_name: string | null;
}

/**
 * The imported factory product master, paginated.
 *
 * The standards index returns a RAW Laravel paginator
 * (`response()->json($standards)`), which puts `total`/`current_page` at the
 * top level — there is no `meta` envelope, unlike every endpoint here that
 * goes through a JsonResource collection. Readers that trusted `meta.total`
 * silently fell back to "however many rows came back", which pinned the pager
 * at one page and made the tail of a 103-row master unreachable.
 *
 * So normalise here rather than at each call site, and accept both shapes:
 * the day the backend wraps this in a ProductionStandardResource, `meta`
 * appears and this keeps working untouched.
 */
type WirePage<T> = { data: T[]; meta?: Paginated<T>['meta'] } & Partial<Paginated<T>['meta']>;

/** The page sizes the workspace offers. The server clamps anything else rather than refusing it. */
export const PRODUCT_STANDARDS_PAGE_SIZES = [25, 50, 100] as const;

/**
 * The workspace query string.
 *
 * The two flags are typed as the literal `1`, not `boolean`, on purpose:
 * Laravel's `boolean` rule accepts `1`, `0`, `"1"` and `"0"` but NOT the
 * string `"true"`, which is exactly what axios puts on the wire for `true`.
 * The old index validated nothing so a boolean worked by accident; the
 * workspace has a FormRequest, and sending `matched_only=true` would answer a
 * live factory's product master with a 422. Typing the literal makes that
 * mistake a compile error instead of a blank page.
 */
export interface ProductStandardsWorkspaceParams {
    view?: ProductStandardsView;
    /** Matches the factory's product name OR the Tally item's name/sku. */
    search?: string;
    /** No item at all, or an item Tally has never heard of — one job. */
    missing_tally?: 1;
    packing_mode?: StandardPackagingMode;
    /** Standards whose cavity count this machine can run. */
    work_center_id?: number;
    status?: string;
    item_id?: number;
    matched_only?: 1;
    page?: number;
    per_page?: number;
}

/**
 * Two approved machine configurations that both apply to the same product on
 * the same machine at the same time. The resolver has to pick one, and until
 * this was reported no screen admitted a choice had been made.
 */
export interface ConfigurationOverlap {
    item_id: number;
    work_center_id: number;
    configuration_ids: number[];
    /** True when the clashing rows disagree on cavities or cycle time — the case that changes expected output. */
    values_differ: boolean;
}

export interface ProductStandardsWorkspacePage {
    data: ProductStandardsWorkspaceRow[];
    meta: Paginated<ProductStandardsWorkspaceRow>['meta'];
    summary: ProductStandardsSummary;
    configuration_overlaps: ConfigurationOverlap[];
}

/**
 * The product-configuration workspace, one REAL page at a time.
 *
 * The endpoint still answers with a raw Laravel paginator (`total` and
 * `current_page` at the top level, no `meta` envelope) with `summary`
 * alongside, so both shapes are still normalised here rather than at the call
 * site.
 *
 * THE DEFAULT VIEW IS PRODUCTION-READY — the server's default, not this
 * function's. A caller that wants everything asks for `view: 'all'`; the
 * Start Batch return does exactly that, because a supervisor sent here by a
 * blocked start is by construction looking at a product that is not ready,
 * and the default view would show them an empty table.
 */
export async function listProductionStandards(
    params: ProductStandardsWorkspaceParams = {},
): Promise<ProductStandardsWorkspacePage> {
    const { data } = await api.get<WirePage<ProductStandardsWorkspaceRow> & { summary?: ProductStandardsSummary; configuration_overlaps?: ConfigurationOverlap[] }>(
        '/production/standards',
        { params },
    );
    const rows = data.data ?? [];

    return {
        data: rows,
        meta: data.meta ?? {
            current_page: data.current_page ?? 1,
            last_page: data.last_page ?? 1,
            per_page: data.per_page ?? rows.length,
            total: data.total ?? rows.length,
        },
        // An older backend sends no summary. Reporting the page's own row
        // count as "all" is the only honest fallback — inventing ready and
        // incomplete numbers would put a verdict on screen nothing computed.
        summary: data.summary ?? { ready: 0, incomplete: 0, all: data.total ?? rows.length },
        // An older backend sends none; an empty list is the honest default.
        configuration_overlaps: data.configuration_overlaps ?? [],
    };
}

/**
 * One product's machine exceptions, by standard.
 *
 * The workspace row already carries a slim copy for the collapsed table; this
 * is the full ProductionConfiguration resource the expanded row's write
 * actions need (status, effective dates, mould, the approval trail). Same
 * service, same machine order — it is a second VIEW of the list, never a
 * second source.
 *
 * A standard with no item attached answers with an empty list rather than an
 * error, because configurations are keyed on the item.
 */
export async function listStandardMachineExceptions(standardId: number): Promise<ProductionConfiguration[]> {
    const { data } = await api.get<{ data: ProductionConfiguration[] }>(
        `/production/standards/${standardId}/machine-exceptions`,
    );
    return data.data ?? [];
}

/**
 * The five product-configuration figures the app is allowed to write, saved
 * on the ITEM MASTER in one round trip.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT `updateItem`. Every gap the readiness gate
 * reports is a `standard ?? item` question — weight, cycle time and cavities
 * fall back from the workbook standard to the item master, pieces-per-box
 * falls back from the resolved packaging to the item, and colour is only ever
 * the item's. So a gap exists precisely when BOTH sides are blank, and filling
 * the item master closes the gap the gate itself will re-evaluate. There is no
 * write endpoint for a standard's own figures (the workbook is their record)
 * and none for a packaging row, so this is the whole of what can honestly be
 * offered — and it is enough for all five.
 *
 * Inventory's shared `UpdateItemPayload` does not carry `nos_per_box`, which
 * the packing gap needs, and the workspace saves all five together. Casting
 * around that would be a lie about a live factory's packing figure; a narrow,
 * truthful payload is not.
 *
 * Every field is `sometimes` on the backend, so an omitted key is left alone
 * and an explicit null clears the figure.
 */
export interface ProductConfigurationFiguresPayload {
    nominal_weight_grams?: number | null;
    standard_cycle_time?: number | null;
    standard_cavities?: number | null;
    colour?: string | null;
    nos_per_box?: number | null;
}

export async function saveProductConfigurationFigures(
    itemId: number,
    payload: ProductConfigurationFiguresPayload,
): Promise<void> {
    await api.put(`/inventory/items/${itemId}`, payload);
}

/**
 * Tally items that might be the one an unattached standard means, scored by
 * name similarity — the same machinery the import's `--diagnose` prints, asked
 * one row at a time.
 *
 * Deliberately NOT fetched per table row: the backend re-queries every active
 * item on each call with no caching, so this belongs behind an opened dialog,
 * never in a column render.
 */
export async function listStandardItemCandidates(standardId: number): Promise<StandardItemCandidate[]> {
    const { data } = await api.get<{
        data: { standard_id: number; source_product_name: string; candidates: StandardItemCandidate[] };
    }>(`/production/standards/${standardId}/item-candidates`);
    return data.data?.candidates ?? [];
}

/**
 * Attach a Tally item to a standard that has none.
 *
 * The row must KEEP ITS IDENTITY — the import's adoption rule exists because a
 * variant imported unlinked and linked later has to become the linked row
 * rather than gain a sibling, or the same mould shows twice (once attached,
 * once "not attached"). That rule lives in the backend; this endpoint is the
 * by-hand equivalent of it.
 */
export async function attachStandardItem(standardId: number, itemId: number): Promise<ProductionStandardRow> {
    const { data } = await api.post<{ data: ProductionStandardRow }>(
        `/production/standards/${standardId}/attach-item`,
        { item_id: itemId },
    );
    return data.data;
}

/**
 * Add a product standard by hand — for a product the workbook does not carry,
 * so it can be set up without waiting for a re-import.
 *
 * FLAT, not a nested `packagings` array: the packing counts use the importer's
 * own row-key names so the backend derives the packaging rows from them with
 * the same code path the workbook import uses. One derivation, so a hand-added
 * product cannot end up packed by different arithmetic than an imported one.
 *
 * Cavities, weight and cycle time are REQUIRED here while the importer accepts
 * them blank — the importer must not lose a real product over a blank cell,
 * but a person filling in a form can simply be told which figure is missing.
 * Every expected-output number is derived from those three.
 *
 * `item_id` is left off by the page on purpose: a hand-added standard starts
 * unattached and gains its item through the same candidates-and-confirm flow
 * as an imported one, so there is exactly one way that decision is ever made.
 */
export interface CreateProductionStandardPayload {
    source_product_name: string;
    cavities: number;
    unit_weight_grams: number;
    cycle_time: number;
    item_id?: number | null;
    carton_spec?: string | null;
    tray_spec?: string | null;
    pouch_spec?: string | null;
    nos_per_pouch?: number | null;
    pouches_per_box?: number | null;
    pouch_nos_per_box?: number | null;
    nos_per_tray?: number | null;
    trays_per_box?: number | null;
    tray_nos_per_box?: number | null;
    notes?: string | null;
}

export async function createProductionStandard(
    payload: CreateProductionStandardPayload,
): Promise<ProductionStandardRow> {
    const { data } = await api.post<{ data: ProductionStandardRow }>('/production/standards', payload);
    return data.data;
}

export async function listStandardCoverage(): Promise<{ data: StandardCoverageRow[] }> {
    const { data } = await api.get<{ data: StandardCoverageRow[] }>('/production/standards/coverage');
    return data;
}

export interface MasterbatchDosingParams {
    /** The PRODUCT (the bottle) — item_id everywhere means the thing produced. */
    item_id?: number;
    /** The masterbatch material. Naming it narrows the answer to one row or none. */
    masterbatch_item_id?: number;
    /** Bottles made — makes each row carry its `suggested_kg` for that count. */
    quantity_produced?: number;
}

/**
 * What masterbatch dosing applies here — grams per bottle from the factory's
 * master. Read-only, so it is safe to call while the completion form is being
 * filled.
 *
 * An empty array means NO dosing is set for that pair: the caller prefills
 * nothing and says nothing extra. It is never an error and never a zero.
 */
export async function listMasterbatchDosings(params: MasterbatchDosingParams): Promise<MasterbatchDosing[]> {
    const { data } = await api.get<{ data: MasterbatchDosing[] }>('/production/masterbatch-dosings', { params });
    return data.data;
}

export interface BatchPreviewParams {
    item_id: number;
    production_standard_id?: number;
    production_standard_packaging_id?: number;
    work_center_id?: number;
    warehouse_id?: number;
    shift_id?: number;
    planned_hours?: number;
    active_cavities?: number;
    /**
     * Which colour is running, when it is known — the answer the supervisor
     * gave at Start Batch, read back off the entry.
     *
     * Sent because colour is what picks the masterbatch, and the endpoint
     * ranks a stated colour above the configuration's and the item master's
     * exactly as Start Batch does. Omitting it made the completion drawer ask
     * for the masterbatch of a WEAKER colour than the run was started with —
     * for most bottle items, no colour at all.
     */
    colour?: string;
}

/**
 * Readiness + estimation for an intended run, before it starts. Read-only,
 * so it is safe to call on every product/machine change while the
 * supervisor fills the form.
 */
export async function getBatchPreview(params: BatchPreviewParams): Promise<BatchPreview> {
    const { data } = await api.get<{ data: BatchPreview }>('/production/shift-production-entries/preview', { params });
    return data.data;
}

/** What Tally is about to receive for an entry, resolved against real masters. */
export async function getVoucherPreview(entryId: number): Promise<VoucherPreview> {
    const { data } = await api.get<{ data: VoucherPreview }>(
        `/production/shift-production-entries/${entryId}/voucher-preview`,
    );
    return data.data;
}

export async function startBatch(payload: StartBatchPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>('/production/shift-production-entries', payload);
    return data.data;
}

export interface CompleteBatchPayload {
    batch_number?: string;
    quantity_produced: number;
    quantity_scrap?: number;
    scrap_reason_id?: number;
    // null allowed: a cleared InputNumber submits null (backend rules are nullable)
    nos_per_tray?: number | null;
    no_of_trays?: number | null;
    nos_per_box?: number | null;
    no_of_box?: number | null;
    no_of_pouches?: number | null;
    // Persisted since Wave A packaging (was a frontend-only derivation helper).
    loose_pieces?: number | null;
    running_hours?: number;
    qc_rejection_kg?: number;
    actual_cycle_time?: number;
    active_cavities?: number;
    helper_name?: string;
    notes?: string;
    /**
     * WITHOUT A WAREHOUSE, deliberately. Nobody on the floor is asked which
     * store a material came out of, so no line can name one; the server
     * resolves each line from where the material actually is (the day bin when
     * the bin holds it, the factory store otherwise). Still optional rather
     * than removed because the endpoint continues to accept an explicit id
     * from non-floor callers, and validates it when sent.
     */
    material_consumptions?: { item_id: number; warehouse_id?: number; quantity_issued_kg: number }[];
    scraps?: { type: 'rejected_finished_good' | 'lumps'; quantity_nos?: number; quantity_kg?: number; scrap_reason_id?: number }[];
    /**
     * Day-bin closing weight per material. Same contract as handover — it
     * is what makes automatic consumption (opening + loaded − closing −
     * returned) computable on a normal completion.
     */
    closing_day_bin?: { item_id: number; quantity_kg: number }[];
    /**
     * Downtime during THIS run — reason + minutes, the note carrying the
     * picked from–to window ("14:30–15:00 — power cut"). Matches
     * ValidatesDowntimeEvents: production_downtime_events stores minutes,
     * not clock times. The backend nets these minutes out of running hours
     * before judging efficiency, mirroring the paper report's B/D section.
     */
    downtime_events?: { downtime_reason_id: number; minutes: number; note?: string }[];
}

export async function completeBatch(id: number, payload: CompleteBatchPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/complete`,
        payload,
    );
    return data.data;
}

/**
 * Correcting a completed batch quality has not touched yet — the floor's own
 * fix to its own count.
 *
 * THE PAYLOAD IS A WHOLE COMPLETION, not a patch. The server reverses what the
 * first completion booked (each consumption line received back at the unit
 * cost its own issue recorded, the finished-goods receipt issued back out) and
 * then re-runs the ordinary completion against what is sent here. So anything
 * omitted is not "left alone" — it is not re-booked. The drawer that calls
 * this loads every recorded figure back into the form for exactly that reason.
 *
 * `amendment_reason` is optional by the backend's own rule: a supervisor
 * fixing their own typo, on their own batch, before anyone else has seen it,
 * is not asked to justify it. Who amended and when are recorded regardless.
 *
 * `material_kg_confirmed` IS THE ESCAPE HATCH FOR ONE SPECIFIC REFUSAL, and it
 * exists on this endpoint alone. The drawer opens with the previously issued
 * material kg already in its boxes and latches them, so a supervisor can move
 * the piece counts and submit the OLD kilograms beside them — the screen shows
 * one arithmetic and the batch gets another. The server refuses exactly that
 * shape (ShiftProductionEntryService::refuseStaleMaterialLines) with a 422 on
 * `errors.material_consumptions` naming both figures, and its message ends
 * "send it again confirming the kilograms are right as typed if that is
 * genuinely what the store issued". THIS FLAG IS HOW IT IS SENT AGAIN. Without
 * it the refusal has no answer and a genuinely weighed figure — the store
 * issued 130 kg, the supervisor is fixing a piece miscount, not the material —
 * is permanently blocked.
 *
 * Never send it unprompted: it is an answer to a refusal that already
 * happened, not a default. The drawer only offers the checkbox after the 422.
 */
export async function amendBatch(
    id: number,
    payload: CompleteBatchPayload & { amendment_reason?: string; material_kg_confirmed?: boolean },
): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/amend`,
        payload,
    );
    return data.data;
}

// The approval chain: PM verifies → Accountant reconciles and posts. The
// accountant is FINAL — their approval is what makes the entry eligible to sync
// to Tally. There is no MD stage.
export async function pmApproveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/pm-approve`);
    return data.data;
}

export async function accountantApproveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/accountant-approve`);
    return data.data;
}


export async function rejectShiftProductionEntry(id: number, reason?: string): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/reject`, {
        reason,
    });
    return data.data;
}

/**
 * Withdraw a batch entered by mistake. NOT a delete: the record and its whole
 * history survive, and the server refuses outright once quality, an approval,
 * carton labels, a Tally voucher or a handover has touched it.
 *
 * The reason is required by the server and is the only thing that afterwards
 * distinguishes "entered by mistake" from a real batch someone made vanish.
 */
export async function cancelShiftProductionEntry(id: number, reason: string): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/cancel`,
        { reason },
    );
    return data.data;
}

export interface SaveShiftSummaryPayload {
    shift_id: number;
    production_date?: string;
    supervisor_id?: number;
    target_production_kg?: number;
    power_consumption_units?: number;
    remarks?: string;
}

export async function saveShiftSummary(payload: SaveShiftSummaryPayload): Promise<ShiftSummary> {
    const { data } = await api.post<{ data: ShiftSummary }>('/production/shift-summaries', payload);
    return data.data;
}

// shiftId omitted means "every shift that ran this date" — the day-wide rollup.
export async function getShiftKpiReport(shiftId: number | undefined, productionDate: string): Promise<ShiftKpiReport> {
    const { data } = await api.get<{ data: ShiftKpiReport }>('/production/shift-summaries/report', {
        params: { shift_id: shiftId, production_date: productionDate },
    });
    return data.data;
}

export async function listReworkOrders(): Promise<Paginated<ReworkOrder>> {
    const { data } = await api.get<Paginated<ReworkOrder>>('/production/rework-orders');
    return data;
}

export interface CreateReworkOrderPayload {
    item_id: number;
    source_work_order_id?: number;
    bom_id?: number;
    warehouse_id: number;
    quantity_input: number;
}

export async function createReworkOrder(payload: CreateReworkOrderPayload): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>('/production/rework-orders', payload);
    return data.data;
}

export async function releaseReworkOrder(id: number): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>(`/production/rework-orders/${id}/release`);
    return data.data;
}

export async function completeReworkOrder(
    id: number,
    payload: { quantity_recovered: number; labor_cost: number },
): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>(`/production/rework-orders/${id}/complete`, payload);
    return data.data;
}

export async function listMachineDowntimeLogs(): Promise<Paginated<MachineDowntimeLog>> {
    const { data } = await api.get<Paginated<MachineDowntimeLog>>('/production/machine-downtime-logs');
    return data;
}

export interface OpenDowntimeLogPayload {
    work_center_id: number;
    shift_id: number;
    production_date?: string;
    nature_of_problem: string;
    from_time?: string;
}

export async function openDowntimeLog(payload: OpenDowntimeLogPayload): Promise<MachineDowntimeLog> {
    const { data } = await api.post<{ data: MachineDowntimeLog }>('/production/machine-downtime-logs', payload);
    return data.data;
}

export async function closeDowntimeLog(
    id: number,
    payload: { remedy?: string; parts_changed?: string; to_time?: string },
): Promise<MachineDowntimeLog> {
    const { data } = await api.post<{ data: MachineDowntimeLog }>(`/production/machine-downtime-logs/${id}/close`, payload);
    return data.data;
}

export async function listMoldChangeLogs(): Promise<Paginated<MoldChangeLog>> {
    const { data } = await api.get<Paginated<MoldChangeLog>>('/production/mold-change-logs');
    return data;
}

export interface OpenMoldChangeLogPayload {
    work_center_id: number;
    shift_id: number;
    production_date?: string;
    changed_from_item_id?: number;
    changed_from_mold_id?: number;
    changed_to_item_id: number;
    changed_to_mold_id: number;
    from_time?: string;
    // Given alongside from_time, the change is logged as already complete
    // in one step instead of needing a separate "Finish Mold Change" call.
    to_time?: string;
}

export async function openMoldChangeLog(payload: OpenMoldChangeLogPayload): Promise<MoldChangeLog> {
    const { data } = await api.post<{ data: MoldChangeLog }>('/production/mold-change-logs', payload);
    return data.data;
}

export async function closeMoldChangeLog(id: number, toTime?: string): Promise<MoldChangeLog> {
    const { data } = await api.post<{ data: MoldChangeLog }>(`/production/mold-change-logs/${id}/close`, {
        to_time: toTime,
    });
    return data.data;
}

export async function listMolds(): Promise<Paginated<Mold>> {
    const { data } = await api.get<Paginated<Mold>>('/production/molds');
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllMolds(): Promise<Paginated<Mold>> {
    const { data } = await api.get<Paginated<Mold>>('/production/molds', { params: { per_page: 1000 } });
    return data;
}

export interface CreateMoldPayload {
    code: string;
    name: string;
    cavity_count?: number;
    status?: MoldStatus;
    notes?: string;
}

export async function createMold(payload: CreateMoldPayload): Promise<Mold> {
    const { data } = await api.post<{ data: Mold }>('/production/molds', payload);
    return data.data;
}

export type UpdateMoldPayload = Partial<CreateMoldPayload>;

export async function updateMold(id: number, payload: UpdateMoldPayload): Promise<Mold> {
    const { data } = await api.put<{ data: Mold }>(`/production/molds/${id}`, payload);
    return data.data;
}

export async function listPowerInterruptionLogs(): Promise<Paginated<PowerInterruptionLog>> {
    const { data } = await api.get<Paginated<PowerInterruptionLog>>('/production/power-interruption-logs');
    return data;
}

export interface CreatePowerInterruptionLogPayload {
    shift_id: number;
    production_date?: string;
    from_time: string;
    to_time: string;
}

export async function createPowerInterruptionLog(payload: CreatePowerInterruptionLogPayload): Promise<PowerInterruptionLog> {
    const { data } = await api.post<{ data: PowerInterruptionLog }>('/production/power-interruption-logs', payload);
    return data.data;
}

export async function listShiftStockCounts(): Promise<Paginated<ShiftStockCount>> {
    const { data } = await api.get<Paginated<ShiftStockCount>>('/production/shift-stock-counts');
    return data;
}

export interface CreateShiftStockCountPayload {
    shift_id: number;
    production_date?: string;
    location_label: string;
    item_id: number;
    quantity_kg: number;
}

export async function createShiftStockCount(payload: CreateShiftStockCountPayload): Promise<ShiftStockCount> {
    const { data } = await api.post<{ data: ShiftStockCount }>('/production/shift-stock-counts', payload);
    return data.data;
}

// ---------------------------------------------------------------------------
// Lot/barcode traceability (Phase 6). Every endpoint below exists ONLY when
// config('production.traceability_enabled') is on — with it off the backend
// 404s and the UI never calls these (gated on settings.traceability_enabled).
// Lots/bags are Inventory's surface (/inventory/*); the day-bin ledger and
// the aggregates are Production's.
// ---------------------------------------------------------------------------

export interface ListMaterialLotsParams {
    item_id?: number;
    grn_id?: number;
    per_page?: number;
    page?: number;
}

export async function listMaterialLots(params?: ListMaterialLotsParams): Promise<Paginated<MaterialLot>> {
    const { data } = await api.get<Paginated<MaterialLot>>('/inventory/material-lots', {
        params,
    });
    return data;
}

/** Register one supplier lot (backend fans out one barcoded bag row per bag). */
export interface CreateMaterialLotPayload {
    grn_id?: number;
    item_id: number;
    supplier_lot_no?: string;
    received_date: string;
    bag_count: number;
    /** Nominal kg per bag; omitted = total / count. */
    bag_weight_kg?: number;
    total_received_kg: number;
    warehouse_id?: number;
    notes?: string;
    /** Supplier barcodes, one per bag; omitted = app-generated LOT{lot}-B{seq}. */
    barcodes?: string[];
    /** Individually weighed bags; omitted = nominal bag_weight_kg. */
    bag_weights?: number[];
}

export async function createMaterialLot(payload: CreateMaterialLotPayload): Promise<MaterialLot> {
    const { data } = await api.post<{ data: MaterialLot }>('/inventory/material-lots', payload);
    return data.data;
}

export async function listMaterialBags(params?: {
    item_id?: number;
    status?: MaterialBagStatus;
}): Promise<Paginated<MaterialBag>> {
    const { data } = await api.get<Paginated<MaterialBag>>('/inventory/material-bags', { params });
    return data;
}

/** FIFO pick list: open in-store bags for the item, oldest lot first. */
export async function getMaterialBagPickList(itemId: number): Promise<MaterialBag[]> {
    const { data } = await api.get<{ data: MaterialBag[] }>('/inventory/material-bags/pick-list', {
        params: { item_id: itemId },
    });
    return data.data;
}

/**
 * Resolve one bag by its scanned barcode — the Shift Floor's central Load
 * Material lookup. The /inventory/material-bags index may not understand a
 * `barcode` filter yet, so the param is sent (a filtering backend answers in
 * one page) AND the open-bag pages are walked client-side as the fallback.
 * Bounded, and only an unknown/mistyped code ever pays the full walk. Only
 * in-store bags qualify: a consumed or already-loaded bag cannot be loaded.
 */
export async function findMaterialBagByBarcode(barcode: string): Promise<MaterialBag | null> {
    const maxPages = 40;
    for (let page = 1; page <= maxPages; page += 1) {
        const { data } = await api.get<Paginated<MaterialBag>>('/inventory/material-bags', {
            params: { barcode, status: 'in_store', page },
        });
        const match = data.data.find((bag) => bag.barcode === barcode);
        if (match) return match;
        if (page >= (data.meta?.last_page ?? page)) return null;
    }
    return null;
}

/** Live day-bin state (per-material balance + bags currently at the machine). */
export async function getDayBin(workCenterId: number): Promise<DayBinState> {
    const { data } = await api.get<{ data: DayBinState }>(`/production/work-centers/${workCenterId}/day-bin`);
    return data.data;
}

export interface LoadDayBinPayload {
    work_center_id: number;
    /** Scanned bag barcode — the scanner-gun path. Exactly one of barcode / material_bag_id. */
    barcode?: string;
    /** Alternative when the bag id is already known (pick-list UI). */
    material_bag_id?: number;
    /** Omit for a full-bag load (the bag's whole remaining_kg); set for a weighed partial. */
    quantity_kg?: number;
    /** The running segment the load belongs to. */
    shift_production_entry_id?: number;
    /**
     * Re-send after a FIFO refusal (422 with code 'fifo_order') — requires
     * the `production.override-fifo` permission and records who overrode.
     */
    override_fifo?: boolean;
}

export async function loadDayBin(payload: LoadDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/load', payload);
    return data.data;
}

/**
 * The Shift Floor's bag load — POST /production/day-bin/load-bag. The bag's
 * kg move store → the internal WIP WAREHOUSE (FactoryDayBin) and enter the
 * COMMON RESIN INPUT. When no day-bin warehouse is configured yet the backend
 * answers 422 with `errors.day_bin` and a message saying so (the Day Bin page
 * names the warehouse).
 *
 * NO MACHINE IS POSTED, and the absence is deliberate rather than optional.
 * The owner's correction (2-Aug): the factory has one common resin input
 * point and a bag is never assigned or scanned to a machine. The server
 * dropped `work_center_id` from its validation rules entirely — an old client
 * that still posts one is accepted and the value ignored — so no key here
 * may reintroduce a dimension the model no longer has.
 */
export interface FactoryDayBinLoadPayload {
    /** The scanned bag barcode. */
    barcode: string;
    /**
     * Kg to move — prefilled with the bag's whole remaining_kg, lowered for
     * a part bag. (The backend also treats an ABSENT value as "whole bag",
     * but this client always weighs in a number.)
     */
    quantity_kg: number;
    /**
     * users.id of the acting supervisor, defaulted to the logged-in user.
     * A note on the movement only — the audit identity stays the
     * authenticated user.
     */
    supervisor_id: number;
    /**
     * THE ACKNOWLEDGEMENT — sent ONLY on the resubmit of a scan the server
     * already refused, never on a first attempt.
     *
     * Sending it speculatively is not a harmless extra field: the server's
     * gate short-circuits the moment a reason is present
     * (`if ($ackReason !== null) return;`), so a pre-filled reason silently
     * disables the check for that scan. Any surface holding this value must
     * clear it on success, on a new barcode, and on close — see the
     * `pendingAck` state on both scan doors.
     */
    balance_ack_reason?: BalanceAckReason;
    /** Optional free text alongside the reason; max 200 chars server-side. */
    balance_ack_note?: string;
}

export async function loadBagToFactoryDayBin(payload: FactoryDayBinLoadPayload): Promise<FactoryDayBinLoadResult> {
    const { data } = await api.post<{ data: FactoryDayBinLoadResult }>('/production/day-bin/load-bag', payload);
    return data.data;
}

/**
 * THE SCAN ACKNOWLEDGEMENT REFUSAL, read off a failed load.
 *
 * The server refuses a load when its running estimate says the COMMON RESIN
 * INPUT still holds a meaningful quantity of the same material, with a 422
 * whose `errors.balance_ack_reason` carries ONE SENTENCE naming that
 * estimated figure — and saying, in the server's own words, that the figure
 * is an estimate which does not count batches still running. Returns that
 * sentence, or null when the failure was anything else (an unknown barcode,
 * no day-bin warehouse, a 500).
 *
 * The sentence is returned VERBATIM and every caller must print it
 * verbatim. It ends with the raw reason tokens because it is the server
 * stating the vocabulary it accepts; the human-worded Select underneath
 * carries that vocabulary for the operator. Do not trim, reword, or
 * regex-strip the tail — the wording is the server's to change, and a
 * client that parses it breaks silently the day it does.
 *
 * Reads `errors.balance_ack_reason`, NOT the top-level `message`: Laravel's
 * top-level message on a multi-error 422 is only the first one, so keying
 * off it would make this gate indistinguishable from any other validation
 * failure on the same request.
 */
export function readBalanceAckRefusal(error: unknown): string | null {
    const errors = (error as { response?: { data?: { errors?: Record<string, unknown> } } })?.response?.data?.errors;
    const messages = errors?.balance_ack_reason;
    if (!Array.isArray(messages) || messages.length === 0) return null;
    const first = messages[0];
    return typeof first === 'string' && first.trim() !== '' ? first : null;
}

export interface ReturnDayBinPayload {
    work_center_id: number;
    item_id: number;
    quantity_kg: number;
    /** Named bag: the kg flows back into it; absent = ledger row only (Vincent Q4 open). */
    material_bag_id?: number;
    shift_production_entry_id?: number;
}

export async function returnDayBin(payload: ReturnDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/return', payload);
    return data.data;
}

export interface CountDayBinPayload {
    work_center_id: number;
    item_id: number;
    /** The weighed/estimated absolute figure — an observation, not a delta. */
    quantity_kg: number;
    shift_production_entry_id?: number;
}

export async function countDayBin(payload: CountDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/count', payload);
    return data.data;
}

/**
 * Backend-computed consumption per material for one entry (segment):
 * opening + Σ loaded − closing − Σ returned. Null (rather than throwing) on a
 * 404 so the Complete Batch drawer degrades gracefully when the backend
 * doesn't serve traceability yet.
 */
export async function getEntryDayBinSummary(entryId: number): Promise<EntryDayBinSummary | null> {
    try {
        const { data } = await api.get<{ data: EntryDayBinSummary }>(
            `/production/shift-production-entries/${entryId}/day-bin`,
        );
        return data.data;
    } catch (error: any) {
        if (error?.response?.status === 404) return null;
        throw error;
    }
}

// ---------------------------------------------------------------------------
// Read-only reports (feat/reports-wave). Envelope rule shared with the
// backend: production = {data: {rows, totals}}; reconciliation =
// {data: {rows}}; traceability = {data: {lots}}.
// ---------------------------------------------------------------------------

export interface ProductionReportParams {
    /** Production date (YYYY-MM-DD). */
    date: string;
    shift_id?: number;
    work_center_id?: number;
}

export async function getProductionReport(params: ProductionReportParams): Promise<ProductionReport> {
    const { data } = await api.get<{ data: ProductionReport }>('/production/reports/production', { params });
    return data.data;
}

export interface ReconciliationReportParams {
    date_from: string;
    date_to: string;
    shift_id?: number;
}

/** Rows come back worst-unaccounted-first — the UI keeps the server order. */
export async function getReconciliationReport(params: ReconciliationReportParams): Promise<ReconciliationReportRow[]> {
    const { data } = await api.get<{ data: { rows: ReconciliationReportRow[] } }>(
        '/production/reports/reconciliation',
        { params },
    );
    return data.data.rows;
}

export interface TraceabilityReportParams {
    /** Lot received_date window — required (same ≤92-day cap as reconciliation). */
    date_from: string;
    date_to: string;
    lot_id?: number;
    item_id?: number;
}

/**
 * Lot → bags → fed machine/segment drill-down. 404s while
 * config('production.traceability_enabled') is off (same flag/middleware as
 * the day-bin routes) — callers only run this with the flag on, but a null
 * degrade keeps a stale tab from crashing on a freshly-disabled backend.
 */
export async function getTraceabilityReport(params: TraceabilityReportParams): Promise<TraceabilityReportRow[] | null> {
    try {
        const { data } = await api.get<{ data: { lots: TraceabilityReportRow[] } }>(
            '/production/reports/traceability',
            { params },
        );
        return data.data.lots;
    } catch (error: any) {
        if (error?.response?.status === 404) return null;
        throw error;
    }
}

export interface HandoverPayload {
    /** The incoming shift taking the machine over. */
    shift_id: number;
    /** The incoming shift's production date (shift-aware, may differ across midnight). */
    production_date?: string;
    /** The incoming operator, when known. */
    operator_id?: number;
    /** Closing day-bin weighments for the OUTGOING segment, one per material. */
    closing_day_bin?: { item_id: number; quantity_kg: number }[];
    /** The outgoing segment's completion figures — mirrors CompleteBatchRequest. */
    completion: CompleteBatchPayload;
}

/**
 * Shift handover: records the outgoing segment's closing day-bin counts,
 * completes it with the given figures, and opens a new entry with the same
 * batch number, product, mold standards and machine; the closing balance
 * carries in as the new segment's opening. Returns the NEW running segment.
 */
export async function handoverShiftProductionEntry(id: number, payload: HandoverPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/handover`,
        payload,
    );
    return data.data;
}

// ---------------------------------------------------------------------
// Configurable production
// ---------------------------------------------------------------------

export async function listProductionConfigurations(params?: {
    work_center_id?: number;
    item_id?: number;
    status?: string;
    search?: string;
    page?: number;
    per_page?: number;
}): Promise<Paginated<ProductionConfiguration>> {
    const { data } = await api.get<Paginated<ProductionConfiguration>>('/production/configurations', { params });
    return data;
}

export async function listMachineConfigurations(workCenterId: number): Promise<{ data: ProductionConfiguration[] }> {
    const { data } = await api.get<{ data: ProductionConfiguration[] }>(
        `/production/work-centers/${workCenterId}/configurations`,
    );
    return data;
}

export interface ProductionConfigurationPayload {
    work_center_id: number;
    item_id: number;
    mold_id?: number | null;
    colour?: string | null;
    unit_weight_grams?: number | null;
    default_cycle_time?: number | null;
    cycle_time_min?: number | null;
    cycle_time_max?: number | null;
    default_cavities?: number | null;
    permitted_cavities?: number[] | null;
    effective_from?: string | null;
    notes?: string | null;
}

export async function createProductionConfiguration(payload: ProductionConfigurationPayload): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>('/production/configurations', payload);
    return data.data;
}

export async function updateProductionConfiguration(
    id: number,
    payload: ProductionConfigurationPayload,
): Promise<ProductionConfiguration> {
    const { data } = await api.put<{ data: ProductionConfiguration }>(`/production/configurations/${id}`, payload);
    return data.data;
}

/** Approval is an act with an actor, not a status field — hence its own call. */
export async function approveProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/approve`);
    return data.data;
}

export async function deactivateProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/deactivate`);
    return data.data;
}

export async function copyProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/copy`);
    return data.data;
}

export async function importProductionConfigurations(
    rows: Record<string, unknown>[],
    dryRun: boolean,
): Promise<ImportResult> {
    const { data } = await api.post<{ data: ImportResult }>('/production/configurations/import', {
        rows,
        dry_run: dryRun,
    });
    return data.data;
}

export async function listDowntimeReasons(selectableAtStart?: boolean): Promise<{ data: DowntimeReason[] }> {
    const { data } = await api.get<{ data: DowntimeReason[] }>('/production/downtime-reasons', {
        params: selectableAtStart ? { selectable_at_start: 1 } : undefined,
    });
    return data;
}

export async function saveDowntimeReason(
    payload: Partial<DowntimeReason> & { code: string; description: string; planning_type: string },
    id?: number,
): Promise<DowntimeReason> {
    const { data } = id
        ? await api.put<{ data: DowntimeReason }>(`/production/downtime-reasons/${id}`, payload)
        : await api.post<{ data: DowntimeReason }>('/production/downtime-reasons', payload);
    return data.data;
}

export async function listFactorySettings(): Promise<{ data: FactorySetting[] }> {
    const { data } = await api.get<{ data: FactorySetting[] }>('/production/factory-settings');
    return data;
}

export async function saveFactorySetting(payload: {
    key: string;
    value: string | null;
    change_reason?: string;
}): Promise<FactorySetting> {
    const { data } = await api.post<{ data: FactorySetting }>('/production/factory-settings', payload);
    return data.data;
}

// ---------------------------------------------------------------------------
// The CENTRAL bin bay. Material is loaded into a machine's day bin once, at
// the bay — the batch screens then read the bin instead of asking for the
// same declaration again.
//
// A load is an inventory LOCATION movement (store → machine day bin): not
// consumption, and never a Tally post. Traceability-gated, same as the
// day-bin endpoints above.
// ---------------------------------------------------------------------------

export interface BinBayAvailabilityParams {
    work_center_id: number;
    /** The MATERIAL whose bin stock is being inspected. */
    item_id?: number;
    /** The PRODUCT about to run — pair with expected_pieces for the recipe block. */
    product_item_id?: number;
    expected_pieces?: number;
}

export async function getBinBayAvailability(
    params: BinBayAvailabilityParams,
): Promise<BinBayAvailabilityResponse> {
    const { data } = await api.get<{ data: BinBayAvailabilityResponse }>('/production/bin-bay/availability', {
        params,
    });
    return data.data;
}

export async function getBinBayHistory(
    workCenterId: number,
    itemId?: number,
    limit?: number,
): Promise<BinBayHistoryRow[]> {
    const { data } = await api.get<{ data: { rows: BinBayHistoryRow[] } }>('/production/bin-bay/history', {
        params: { work_center_id: workCenterId, item_id: itemId, limit },
    });
    return data.data.rows;
}

export interface BinBayLoadPayload {
    work_center_id: number;
    /** The code on the bag — typed, gun-scanned or read by the camera. */
    barcode: string;
    /** Omit for a full-bag load (its whole remaining_kg); set for a weighed partial pour. */
    quantity_kg?: number;
    /** Optional: loading is central and normally not tied to a batch. */
    shift_production_entry_id?: number;
    /**
     * Re-send after a FIFO refusal (422 with code 'fifo_order') — requires
     * the `production.override-fifo` permission and records who overrode.
     */
    override_fifo?: boolean;
    /**
     * Credit the load to someone else (a users row, not an employee).
     * Defaults to the authenticated user; naming another needs production.manage.
     */
    loaded_by?: number;
}

export async function loadBinBay(payload: BinBayLoadPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/bin-bay/load', payload);
    return data.data;
}

// ---------------------------------------------------------------------------
// The FACTORY DAY BIN (central, always available — NOT flag-gated).
//
// The day bin is a warehouse, so these are deliberately thin:
//  - the read is the warehouse plus its stock balances,
//  - naming the warehouse is one app setting,
//  - LOADING it is the EXISTING inventory transfer endpoint. No new write
//    path was added for it: material moving store → day bin is a location
//    movement, not consumption, and never a Tally post.
// ---------------------------------------------------------------------------

/** What the factory day bin holds right now (warehouse null = not configured). */
export async function getFactoryDayBin(): Promise<FactoryDayBin> {
    const { data } = await api.get<{ data: FactoryDayBin }>('/production/factory-day-bin');
    return data.data;
}

/**
 * ESTIMATED RESIN REMAINING IN THE COMMON INPUT — one row per material:
 * every kg loaded into the factory's one input point, less the calculated
 * consumption of that material ACROSS ALL MACHINES.
 *
 * NO MACHINE DIMENSION, and no way to ask for one. The owner's correction
 * (2-Aug): the factory has one common resin input point, a bag is never
 * assigned or scanned to a machine, and a per-machine balance was a number
 * with no physical referent. The route PATH is unchanged so the deploy is
 * one-sided; the PAYLOAD is a flat list. The `?work_center_id=` parameter is
 * still accepted by the server and DELIBERATELY IGNORED, so this client
 * stops sending it rather than asking for a narrowing that cannot happen.
 *
 * AN EMPTY ARRAY IS A REAL ANSWER and means "nothing has been loaded yet" —
 * never "the input is empty". See CommonResinMaterial.
 */
export async function getCommonResinEstimate(): Promise<CommonResinMaterial[]> {
    const { data } = await api.get<{ data: CommonResinMaterial[] }>('/production/machine-resin');
    return data.data;
}

/** Name the warehouse that IS the factory day bin (null clears it). */
export async function setDayBinWarehouse(warehouseId: number | null): Promise<number | null> {
    const { data } = await api.put<{ data: { day_bin_warehouse_id: number | null } }>(
        '/production/settings/day-bin-warehouse',
        { warehouse_id: warehouseId },
    );
    return data.data.day_bin_warehouse_id;
}

/**
 * The two warehouse ROLES Start Batch and completion resolve silently:
 * where finished goods land, and where raw material issues from when the
 * day bin cannot answer. `*_resolved_*` is what the resolver would use
 * TODAY (setting, else the single Tally-linked warehouse); the plain ids
 * are what is actually stored. Both are shown so the screen can honestly
 * say "nothing set — resolving to X" versus "nothing set and nothing
 * resolvable", which is the exact state that blocked the team's first
 * batch: the backend refused to guess, correctly, but no screen existed
 * to make the choice it asked for.
 */
export interface FactoryWarehouseSettings {
    finished_goods_warehouse_id: number | null;
    raw_material_warehouse_id: number | null;
    /**
     * The PACKING MATERIAL STORE — cartons, trays, film pouches, tape.
     *
     * The one role with NO FALLBACK, deliberately: a factory with a single
     * Tally godown genuinely has nothing to choose between for resin or
     * bottles, but a Packing Material Store is a SECOND named place, and the
     * whole reason the owner named it separately is that cartons do not come
     * out of the resin store. So `packing_material_resolved_warehouse_id` is
     * always the same figure as the configured one, and when it is null the
     * Tally preview names the gap instead of posting somewhere plausible.
     * Nothing in the production path refuses a shift over it — the shift is
     * real and gets recorded; it is the POSTING that waits.
     *
     * Optional: a backend that predates the role sends neither key.
     */
    packing_material_warehouse_id?: number | null;
    finished_goods_resolved_warehouse_id: number | null;
    raw_material_resolved_warehouse_id: number | null;
    packing_material_resolved_warehouse_id?: number | null;
}

/** The three warehouse roles the settings endpoint stores by name. */
export type FactoryWarehouseRole =
    | 'finished_goods_warehouse_id'
    | 'raw_material_warehouse_id'
    | 'packing_material_warehouse_id';

export async function getFactoryWarehouseSettings(): Promise<FactoryWarehouseSettings> {
    const { data } = await api.get<{ data: FactoryWarehouseSettings }>('/production/settings');
    return data.data as FactoryWarehouseSettings;
}

export async function setFactoryWarehouse(
    role: FactoryWarehouseRole,
    warehouseId: number | null,
): Promise<FactoryWarehouseSettings> {
    const { data } = await api.put<{ data: FactoryWarehouseSettings }>(
        '/production/settings/factory-warehouses',
        { [role]: warehouseId },
    );
    return data.data;
}

export interface LoadFactoryDayBinPayload {
    item_id: number;
    from_warehouse_id: number;
    to_warehouse_id: number;
    /** Kg (or the material's own unit) being moved into the bin. */
    quantity: number;
    /** 'YYYY-MM-DD HH:mm:ss' — when it physically went in, not when it was typed. */
    movement_date?: string;
    reference?: string;
    notes?: string;
}

/**
 * Move material store → factory day bin. Posts Inventory's existing transfer
 * endpoint (the one loader for a location move); called from here rather than
 * through features/inventory/api.ts only because that module's TransferPayload
 * does not carry movement_date, and the floor backdates a load routinely.
 *
 * Guarded by `module:inventory` server-side — a production-only login gets a
 * 403 here, which the page reports as a permission message.
 */
export async function loadFactoryDayBin(payload: LoadFactoryDayBinPayload): Promise<void> {
    await api.post('/inventory/stock-movements/transfers', payload);
}

/**
 * The materials the Day Bin page may load — raw material only (resin,
 * masterbatch: everything bought by the kg), NEVER the bottle list.
 *
 * The backend decides what counts: this is the day bin's own picker route,
 * which returns active kg-uom items with their current store kg. The kg filter
 * is deliberately not re-derived here — one definition of "raw material",
 * server-side, is why the endpoint exists.
 */
export async function listRawMaterials(): Promise<RawMaterialOption[]> {
    const { data } = await api.get<{ data: RawMaterialOption[] }>('/production/factory-day-bin/raw-materials');
    return data.data;
}

/**
 * Mint (or re-fetch) the carton barcodes for a completed batch. Idempotent
 * server-side: the first call creates them from the packed count, every later
 * call returns the same rows — so "generate" and "reprint" are one action.
 */
export async function generateCartons(entryId: number): Promise<FinishedCarton[]> {
    const { data } = await api.post<{ data: FinishedCarton[] }>(
        `/production/shift-production-entries/${entryId}/cartons`,
    );
    return data.data;
}

/**
 * Resolve one scanned carton code to its box — item, pieces, batch spine,
 * and whether it already left. 422 when no carton carries the code.
 */
export async function lookupCarton(cartonNo: string): Promise<FinishedCarton> {
    const { data } = await api.get<{ data: FinishedCarton }>(
        `/production/cartons/${encodeURIComponent(cartonNo)}`,
    );
    return data.data;
}

/**
 * One choosable packing material — an item, and the unit its quantity is in.
 */
export interface PackingMaterialOption {
    id: number;
    name: string;
    uom: string | null;
}

/**
 * The lists the completion screen's packing pickers are built from, keyed by
 * kind: `carton`, `tray`, `pouch_film`, `tape`.
 *
 * Fetched once per session rather than per row. The catalogue changes when Tally
 * masters are pulled, not while a supervisor is filling in a shift, and four
 * requests per drawer open would be four requests for the same answer.
 */
export async function listPackingMaterialOptions(): Promise<Record<string, PackingMaterialOption[]>> {
    const { data } = await api.get<{ data: Record<string, PackingMaterialOption[]> }>(
        '/production/packing-material-options',
    );
    return data.data;
}
