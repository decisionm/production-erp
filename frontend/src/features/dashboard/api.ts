import { api } from '@/lib/api';
import type { DashboardSummary } from './types';

export async function getDashboardSummary(): Promise<DashboardSummary> {
    const { data } = await api.get<{ data: DashboardSummary }>('/dashboard/summary');
    return data.data;
}
