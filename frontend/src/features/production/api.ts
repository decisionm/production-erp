import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    Bom,
    CapacityWorkCenterLoad,
    MrpNetRequirement,
    ReworkOrder,
    Routing,
    ScrapReason,
    SubcontractOrder,
    WorkCenter,
    WorkOrder,
} from './types';

export async function listWorkCenters(): Promise<Paginated<WorkCenter>> {
    const { data } = await api.get<Paginated<WorkCenter>>('/production/work-centers');
    return data;
}

export interface CreateWorkCenterPayload {
    code: string;
    name: string;
    capacity_hours_per_day?: number;
}

export async function createWorkCenter(payload: CreateWorkCenterPayload): Promise<WorkCenter> {
    const { data } = await api.post<{ data: WorkCenter }>('/production/work-centers', payload);
    return data.data;
}

export async function updateWorkCenter(id: number, payload: { capacity_hours_per_day?: number }): Promise<WorkCenter> {
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

export interface CreateScrapReasonPayload {
    code: string;
    name: string;
}

export async function createScrapReason(payload: CreateScrapReasonPayload): Promise<ScrapReason> {
    const { data } = await api.post<{ data: ScrapReason }>('/production/scrap-reasons', payload);
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
