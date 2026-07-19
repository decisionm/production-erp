import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    Asset,
    AssetStatus,
    MaintenanceSchedule,
    MaintenanceWorkOrder,
    MaintenanceWorkOrderType,
    ReliabilityReport,
} from './types';

export async function listAssets(): Promise<Paginated<Asset>> {
    const { data } = await api.get<Paginated<Asset>>('/maintenance/assets');
    return data;
}

export interface CreateAssetPayload {
    code: string;
    name: string;
    category?: string;
    location?: string;
    purchase_date?: string;
    purchase_cost?: number;
}

export async function createAsset(payload: CreateAssetPayload): Promise<Asset> {
    const { data } = await api.post<{ data: Asset }>('/maintenance/assets', payload);
    return data.data;
}

export interface UpdateAssetPayload {
    code?: string;
    name?: string;
    category?: string;
    location?: string;
    purchase_date?: string;
    purchase_cost?: number;
    status?: AssetStatus;
}

export async function updateAsset(id: number, payload: UpdateAssetPayload): Promise<Asset> {
    const { data } = await api.put<{ data: Asset }>(`/maintenance/assets/${id}`, payload);
    return data.data;
}

export async function listMaintenanceSchedules(assetId?: number): Promise<Paginated<MaintenanceSchedule>> {
    const { data } = await api.get<Paginated<MaintenanceSchedule>>('/maintenance/schedules', {
        params: assetId ? { asset_id: assetId } : undefined,
    });
    return data;
}

export interface CreateMaintenanceSchedulePayload {
    asset_id: number;
    name: string;
    frequency_days: number;
    next_due_date: string;
}

export async function createMaintenanceSchedule(payload: CreateMaintenanceSchedulePayload): Promise<MaintenanceSchedule> {
    const { data } = await api.post<{ data: MaintenanceSchedule }>('/maintenance/schedules', payload);
    return data.data;
}

export async function generateDueWorkOrders(): Promise<MaintenanceWorkOrder[]> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder[] }>('/maintenance/schedules/generate-due');
    return data.data;
}

export async function listMaintenanceWorkOrders(assetId?: number): Promise<Paginated<MaintenanceWorkOrder>> {
    const { data } = await api.get<Paginated<MaintenanceWorkOrder>>('/maintenance/work-orders', {
        params: assetId ? { asset_id: assetId } : undefined,
    });
    return data;
}

export interface CreateMaintenanceWorkOrderPayload {
    asset_id: number;
    type: MaintenanceWorkOrderType;
    description?: string;
    assigned_to?: number;
}

export async function createMaintenanceWorkOrder(payload: CreateMaintenanceWorkOrderPayload): Promise<MaintenanceWorkOrder> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder }>('/maintenance/work-orders', payload);
    return data.data;
}

export async function addMaintenanceWorkOrderPart(
    id: number,
    payload: { item_id: number; warehouse_id: number; quantity: number },
): Promise<MaintenanceWorkOrder> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder }>(`/maintenance/work-orders/${id}/parts`, payload);
    return data.data;
}

export async function startMaintenanceWorkOrder(id: number): Promise<MaintenanceWorkOrder> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder }>(`/maintenance/work-orders/${id}/start`);
    return data.data;
}

export async function completeMaintenanceWorkOrder(id: number, laborCost?: number): Promise<MaintenanceWorkOrder> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder }>(`/maintenance/work-orders/${id}/complete`, {
        labor_cost: laborCost,
    });
    return data.data;
}

export async function cancelMaintenanceWorkOrder(id: number): Promise<MaintenanceWorkOrder> {
    const { data } = await api.post<{ data: MaintenanceWorkOrder }>(`/maintenance/work-orders/${id}/cancel`);
    return data.data;
}

export async function getReliabilityReport(assetId: number): Promise<ReliabilityReport> {
    const { data } = await api.get<{ data: ReliabilityReport }>('/maintenance/reports/reliability', {
        params: { asset_id: assetId },
    });
    return data.data;
}
