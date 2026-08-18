import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    BagScanPayload,
    CreateMaterialRequestPayload,
    IssueToProductionPayload,
    MaterialFlowMaterial,
    MaterialRequest,
    MaterialRequestFilters,
    ProductionFloorStock,
    ReturnToStorePayload,
    StoreIssue,
    StoreIssueBagScan,
} from './types';

/**
 * The Phase 7.5 material-flow endpoints, all built from one prefix so a
 * single edit re-points the screens if the backend groups them elsewhere.
 * Every call is append-only in the API's sense: lifecycle changes are POST
 * actions, never PUT and never DELETE.
 */
export const MATERIAL_FLOW_BASE = '/inventory';

/* ------------------------------- requests ------------------------------- */

/** The STORE'S QUEUE. Every filter is passed to the server; none is applied here. */
export async function listMaterialRequests(filters: MaterialRequestFilters = {}): Promise<Paginated<MaterialRequest>> {
    const { data } = await api.get<Paginated<MaterialRequest>>(`${MATERIAL_FLOW_BASE}/material-requests`, {
        params: { per_page: 100, ...filters },
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

export async function listStoreIssues(
    params: { material_request_id?: number; status?: string; item_id?: number } = {},
): Promise<Paginated<StoreIssue>> {
    const { data } = await api.get<Paginated<StoreIssue>>(`${MATERIAL_FLOW_BASE}/store-issues`, {
        params: { per_page: 100, ...params },
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
export async function listProductionFloorStock(): Promise<ProductionFloorStock[]> {
    const { data } = await api.get<{ data: ProductionFloorStock[] }>(`${MATERIAL_FLOW_BASE}/production-floor-stock`);
    return data.data;
}
