/**
 * THE SCHEDULES LIST'S URL STATE (03-Sep-2026) — the pure half of
 * SchedulesPage: which URL keys the page owns, which columns the server
 * sorts on (ListMaintenanceSchedulesRequest::SORTABLE), and how the URL
 * becomes the request. Module-level, as useListParams requires; pinned by
 * scheduleList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { MaintenanceScheduleListFilters } from './types';

/** The columns the server sorts the list on, besides id. */
export const SCHEDULE_SORT_FIELDS: readonly string[] = ['id', 'name', 'frequency_days', 'next_due_date'];
/** MaintenanceScheduleService's order when no sort is asked for: soonest due first. */
export const SCHEDULE_DEFAULT_SORT = 'next_due_date';

export const SCHEDULE_LIST_SPEC: ListParamsSpec = {
    numbers: ['asset_id'],
    strings: ['sort'],
    allowed: { sort: SCHEDULE_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type ScheduleListParams = ListParams & { asset_id?: number; sort?: string };

/** The page's URL → the request the server gets. Compacted: `{}` and `{ sort: '' }` are one key. */
export function scheduleServerFilters(params: ScheduleListParams): MaintenanceScheduleListFilters {
    return compactParams(params);
}

/** Under the ['maintenance', 'schedules'] prefix every schedule mutation already invalidates. */
export function schedulesQueryKey(filters: MaintenanceScheduleListFilters) {
    return ['maintenance', 'schedules', 'list', filters] as const;
}
