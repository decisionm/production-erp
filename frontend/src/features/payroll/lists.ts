import { type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { PayrollRunListFilters, PayrollRunStatus, PayslipListFilters } from './types';

/**
 * THE TWO PAYROLL LISTS' URL STATE — runs and payslips — and how each URL
 * becomes the server's filters (the material-flow lists' pattern).
 *
 * Pure, so the mapping is pinned by the render tests, which seed the exact
 * query key a page derives from its URL. Neither page holds a filter in
 * component state any more: a refresh, Back, or the runs page's
 * "View Payslips" link (`?payroll_run_id=7`) all land on the same view.
 */

/* ------------------------------ payroll runs ----------------------------- */

export const RUN_STATUS_CHOICES: readonly PayrollRunStatus[] = ['draft', 'processed', 'paid'];

/** Module-level, as useListParams requires. */
export const RUNS_LIST_SPEC: ListParamsSpec = {
    strings: ['status'],
    allowed: { status: RUN_STATUS_CHOICES },
};

/** The URL → the request the server gets. Compacted: `{}` and `{ q: '' }` are one key. */
export function runsServerFilters(params: PayrollRunListFilters): PayrollRunListFilters {
    return compactParams(params);
}

export function runsQueryKey(filters: PayrollRunListFilters) {
    return ['payroll', 'runs', 'list', filters] as const;
}

/* -------------------------------- payslips ------------------------------- */

export const PAYSLIPS_LIST_SPEC: ListParamsSpec = {
    numbers: ['payroll_run_id', 'employee_id'],
};

export function payslipsServerFilters(params: PayslipListFilters): PayslipListFilters {
    return compactParams(params);
}

export function payslipsQueryKey(filters: PayslipListFilters) {
    return ['payroll', 'payslips', 'list', filters] as const;
}

/* -------------------------------- the words ------------------------------ */

export const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
] as const;

/** "August 2026" — how every payroll screen names a run. */
export function periodLabel(run: { year: number; month: number }): string {
    return `${MONTH_NAMES[run.month - 1] ?? run.month} ${run.year}`;
}
