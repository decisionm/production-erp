import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    Bom,
    MrpNetRequirement,
    Routing,
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
}

export async function createWorkCenter(payload: CreateWorkCenterPayload): Promise<WorkCenter> {
    const { data } = await api.post<{ data: WorkCenter }>('/production/work-centers', payload);
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

export async function completeWorkOrder(id: number, quantityCompleted: number): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>(`/production/work-orders/${id}/complete`, {
        quantity_completed: quantityCompleted,
    });
    return data.data;
}

export async function getMrpNetRequirements(itemId: number, quantity: number): Promise<MrpNetRequirement[]> {
    const { data } = await api.get<{ data: MrpNetRequirement[] }>('/production/mrp/net-requirements', {
        params: { item_id: itemId, quantity },
    });
    return data.data;
}
