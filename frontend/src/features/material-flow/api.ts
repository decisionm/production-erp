import { api } from '@/lib/api';
import type { StockMovement } from '@/features/inventory/types';
import { compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type {
    BagScanPayload,
    CreateMaterialRequestPayload,
    IssueToProductionPayload,
    MaterialFlowMaterial,
    MaterialRequest,
    MaterialRequestFilters,
    ProductionFloorStockResult,
    ProductionReturnPayload,
    ProductionReturnable,
    ReturnToStorePayload,
    StoreIssue,
    StoreIssueBagScan,
    StoreIssueListParams,
} from './types';

/**
 * The Phase 7.5 material-flow endpoints, all built from one prefix so a
 * single edit re-points the screens if the backend groups them elsewhere.
 * Every call is append-only in the API's sense: lifecycle changes are POST
 * actions, never PUT and never DELETE.
 */
export const MATERIAL_FLOW_BASE = '/inventory';

/* ------------------------------- requests ------------------------------- */

/**
 * The STORE'S QUEUE (and the floor's own list). Every filter, the search,
 * the page and the page size are passed to the server; none is applied
 * here. No page size is defaulted any more: the server's own default
 * stands and the pager reaches every page — a hardcoded 100 was a list
 * silently cut at row 100 with nothing on screen to say so.
 */
export async function listMaterialRequests(filters: MaterialRequestFilters = {}): Promise<Paginated<MaterialRequest>> {
    const { data } = await api.get<Paginated<MaterialRequest>>(`${MATERIAL_FLOW_BASE}/material-requests`, {
        params: compactParams(filters),
    });
    return data;
}

export async function getMaterialRequest(id: number): Promise<MaterialRequest> {
    const { data } = await api.get<{ data: MaterialRequest }>(`${MATERIAL_FLOW_BASE}/material-requests/${id}`);
    return data.data;
}

export async function createMaterialRequest(payload: CreateMaterialRequestPayload): Promise<MaterialRequest> {
    const { data } = await api.post<{ data: MaterialRequest }>(`${MATERIAL_FLOW_BASE}/material-requests`, payload);
    return data.data;
}

export async function submitMaterialRequest(id: number): Promise<MaterialRequest> {
    const { data } = await api.post<{ data: MaterialRequest }>(`${MATERIAL_FLOW_BASE}/material-requests/${id}/submit`);
    return data.data;
}

export async function cancelMaterialRequest(id: number, reason: string): Promise<MaterialRequest> {
    const { data } = await api.post<{ data: MaterialRequest }>(
        `${MATERIAL_FLOW_BASE}/material-requests/${id}/cancel`,
        { reason },
    );
    return data.data;
}

/**
 * The materials the floor may ask the store for — ONE server read.
 *
 * The server decides membership. This used to fetch the WHOLE item master
 * (`/inventory/items?per_page=1000`) and hand every row to the picker, which is
 * how a finished good came to be offered as a requestable input. Eligibility is
 * now a configured property of the item and the endpoint returns only what
 * qualifies, so there is no browser rule to keep in step — and no name, SKU or
 * unit is inspected anywhere on this path.
 *
 * `machine_applies` (FC-01/Q50) is computed server-side by the same predicate
 * the write-side guard refuses on. It used to be derived here by fetching a
 * second, day-bin-named list and testing membership; that second read is gone,
 * which also takes the last Day Bin dependency off this screen.
 */
export async function listRequestableMaterials(): Promise<MaterialFlowMaterial[]> {
    const { data } = await api.get<{ data: MaterialFlowMaterial[] }>(`${MATERIAL_FLOW_BASE}/requestable-materials`);
    return data.data;
}

/* -------------------------------- issues -------------------------------- */

/**
 * THE HANDOVER — fulfil a request, in part or in full. This is the step that
 * moves stock Raw Material Store → Production/WIP. It is an ISSUE, not a
 * consumption: the material is still the factory's, still in stock, and what
 * a batch used is worked out later, elsewhere.
 */
export async function issueToProduction(payload: IssueToProductionPayload): Promise<StoreIssue> {
    const { data } = await api.post<{ data: StoreIssue }>(`${MATERIAL_FLOW_BASE}/store-issues`, payload);
    return data.data;
}

/** The handovers, narrowed and paged by the server — see StoreIssueListParams. */
export async function listStoreIssues(params: StoreIssueListParams = {}): Promise<Paginated<StoreIssue>> {
    const { data } = await api.get<Paginated<StoreIssue>>(`${MATERIAL_FLOW_BASE}/store-issues`, {
        params: compactParams(params),
    });
    return data;
}

export async function getStoreIssue(id: number): Promise<StoreIssue> {
    const { data } = await api.get<{ data: StoreIssue }>(`${MATERIAL_FLOW_BASE}/store-issues/${id}`);
    return data.data;
}

/**
 * One bag scanned at the handover: bag, lot, kg, issued by, received by.
 *
 * Answers with the SCAN, not the whole handover, so callers refetch the issue
 * afterwards rather than treating this as the new state of it. The route is
 * behind the traceability gate — with that switched off it does not exist,
 * which is a 404 and not a bad barcode; the screens say which.
 */
export async function scanBagForIssue(issueId: number, payload: BagScanPayload): Promise<StoreIssueBagScan> {
    const { data } = await api.post<{ data: StoreIssueBagScan }>(
        `${MATERIAL_FLOW_BASE}/store-issues/${issueId}/bag-scans`,
        payload,
    );
    return data.data;
}

/** The HTTP status behind a refusal, where there was one. */
export function apiStatus(error: unknown): number | null {
    return (error as { response?: { status?: number } })?.response?.status ?? null;
}

/** Close the handover. Nothing moves in stock — see the words helper. */
export async function completeStoreIssue(issueId: number): Promise<StoreIssue> {
    const { data } = await api.post<{ data: StoreIssue }>(`${MATERIAL_FLOW_BASE}/store-issues/${issueId}/complete`);
    return data.data;
}

export async function cancelStoreIssue(issueId: number, reason: string): Promise<StoreIssue> {
    const { data } = await api.post<{ data: StoreIssue }>(`${MATERIAL_FLOW_BASE}/store-issues/${issueId}/cancel`, {
        reason,
    });
    return data.data;
}

/** Unused material coming back: Production/WIP → Raw Material Store. */
export async function returnToStore(issueId: number, payload: ReturnToStorePayload): Promise<StoreIssue> {
    const { data } = await api.post<{ data: StoreIssue }>(
        `${MATERIAL_FLOW_BASE}/store-issues/${issueId}/returns`,
        payload,
    );
    return data.data;
}

/* ------------------------- the daily return home ------------------------- */

/**
 * What is standing in the production area and how much of it may come back
 * which way. Not paginated on purpose: this is one row per material standing
 * on the floor, a list the factory keeps short by returning it.
 */
export async function listProductionReturnable(q?: string): Promise<ProductionReturnable[]> {
    const { data } = await api.get<{ data: ProductionReturnable[] }>(
        `${MATERIAL_FLOW_BASE}/production-returns/returnable`,
        { params: { q } },
    );
    return data.data;
}

/** The return itself — attributed and unattributed lines in ONE call. */
export async function recordProductionReturn(payload: ProductionReturnPayload): Promise<void> {
    await api.post(`${MATERIAL_FLOW_BASE}/production-returns`, payload);
}

/**
 * The message a refused action should show: the server's own words when it
 * gave any, because the refusals that matter here (a machine named on a
 * common-input request, an over-issue, an over-return) are the backend's
 * rules and its wording is the authority on them.
 */
export function apiRefusalMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response;
    const firstFieldError = Object.values(response?.data?.errors ?? {})[0]?.[0];
    return firstFieldError ?? response?.data?.message ?? fallback;
}

/**
 * What is already standing on the production floor.
 *
 * A separate read from the queue on purpose: it answers "what do we already
 * have?" rather than "what did we ask for?", and the server sources it from
 * Production/WIP stock balances.
 */
export async function listProductionFloorStock(): Promise<ProductionFloorStockResult> {
    const { data } = await api.get<ProductionFloorStockResult>(`${MATERIAL_FLOW_BASE}/production-floor-stock`);

    // `meta.wip_configured` is the difference between "the floor is empty" and
    // "nobody has told the ERP where the floor is". Both arrive as an empty
    // list, and only one of them may be reported as the floor being clear.
    return { data: data.data ?? [], meta: data.meta ?? { wip_configured: true } };
}

/* --------------------------- the one history ---------------------------- */

/**
 * EVERY HANDOVER AND EVERY RETURN, newest first — the single movement
 * history behind the Store ↔ Production screen.
 *
 * WHY IT NAMES A WAREHOUSE, and why that is not an optional refinement.
 * `recordTransfer` writes TWO rows for one physical movement — a
 * `transfer_out` on the store and a `transfer_in` on Production/WIP — and
 * stamps BOTH with the same purpose (StockMovementService.php:287, :303;
 * StockMovementPurpose.php:35-38 says so in as many words). Filtering on
 * purpose ALONE therefore lists every issue and every return twice, and
 * makes `meta.total` report double the handovers the factory actually made.
 * Naming the Production/WIP side selects exactly one leg of each pair,
 * because recordTransfer refuses from == to (:253-257) so the two legs are
 * never in the same warehouse. It is a WHERE predicate, so the collapse
 * happens before the LIMIT and pagination counts events rather than legs.
 *
 * DIRECTION IS THEN UNAMBIGUOUS from `type` read against that one leg: an
 * issue is the `transfer_in` (material arriving INTO production), a return
 * is the `transfer_out` (material leaving it).
 *
 * WHAT THIS LIST IS NOT. It is not a running balance and must never be
 * presented as one. Consumption — the batch actually using the material — is
 * a separate event with its own purpose, and it is deliberately NOT here:
 * `FactoryWarehouseResolver::consumptionSource` books it against
 * Production/WIP only while `productionWipIsInPlay` holds (:376-385, a
 * balance test), so consumption rows would appear for most materials and
 * silently vanish for exactly the over-drawn ones — a partial ledger that
 * reads as a complete one. What is actually standing in production comes
 * from the balance read (`listProductionReturnable`), never from summing
 * these rows.
 *
 * A NOTE FOR ANY OTHER CONSUMER of `/inventory/stock-movements`: the purpose
 * filter is a general capability, but the de-duplication is NOT automatic.
 * Send `?purpose=issue_to_production,return_from_production` without a
 * warehouse and you get both legs of every transfer, with a 200 and no
 * signal. `transfer_group` (StockMovementResource.php:45) is the other way
 * to collapse a pair.
 */
export async function listStoreProductionMovements(params: {
    /** The Production/WIP row, from the warehouses index's own meta. */
    wipWarehouseId: number;
    itemId?: number;
    page?: number;
    perPage?: number;
    /** ListStockMovementsRequest::SORTABLE in the ListSort spelling; absent is newest first. */
    sort?: string;
}): Promise<Paginated<StockMovement>> {
    const { data } = await api.get<Paginated<StockMovement>>(`${MATERIAL_FLOW_BASE}/stock-movements`, {
        params: {
            purpose: 'issue_to_production,return_from_production',
            warehouse_id: params.wipWarehouseId,
            item_id: params.itemId,
            sort: params.sort,
            page: params.page,
            per_page: params.perPage,
        },
    });
    return data;
}
