import { type ListParamsSpec, compactParams } from '@/lib/listParams';
import type {
    PayrollRunListFilters,
    PayrollRunStatus,
    PayslipListFilters,
    SalaryComponentListFilters,
    SalaryStructureListFilters,
} from './types';

/**
 * THE FOUR PAYROLL LISTS' URL STATE — runs, payslips, salary components,
 * salary structures — and how each URL becomes the server's filters (the
 * material-flow lists' pattern).
 *
 * Pure, so the mapping is pinned by the render tests, which seed the exact
 * query key a page derives from its URL. No page holds a filter in
 * component state any more: a refresh, Back, or the runs page's
 * "View Payslips" link (`?payroll_run_id=7`) all land on the same view.
 *
 * `sort` (03-Sep-2026) is the server's own spelling — a bare column for
 * ascending, `-column` for descending, absent for the list's default — and
 * only a column the matching List*Request sorts on is let through, so a
 * mistyped link falls back to the default order rather than a 422. Each
 * DEFAULT_SORT is what the service orders by when nothing is asked.
 */

/** Bare and "-" prefixed: the two spellings the URL's `sort` may take per column. */
function sortOptions(fields: readonly string[]): string[] {
    return fields.flatMap((field) => [field, `-${field}`]);
}

/* ------------------------------ payroll runs ----------------------------- */

export const RUN_STATUS_CHOICES: readonly PayrollRunStatus[] = ['draft', 'processed', 'paid'];

/**
 * ListPayrollRunsRequest::SORTABLE. `period` is year and month read as one
 * — the two columns the Period cell prints — and the two stamps are
 * nullable; the server puts an undated run last either way.
 */
export const RUNS_SORT_FIELDS: readonly string[] = ['period', 'status', 'processed_at', 'paid_at'];
/** PayrollRunService: newest period first. */
export const RUNS_DEFAULT_SORT = '-period';

/** Module-level, as useListParams requires. */
export const RUNS_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'sort'],
    allowed: { status: RUN_STATUS_CHOICES, sort: sortOptions(RUNS_SORT_FIELDS) },
};

/** The URL → the request the server gets. Compacted: `{}` and `{ q: '' }` are one key. */
export function runsServerFilters(params: PayrollRunListFilters): PayrollRunListFilters {
    return compactParams(params);
}

export function runsQueryKey(filters: PayrollRunListFilters) {
    return ['payroll', 'runs', 'list', filters] as const;
}

/* -------------------------------- payslips ------------------------------- */

/** ListPayslipsRequest::SORTABLE — the three stored figures. */
export const PAYSLIPS_SORT_FIELDS: readonly string[] = ['gross_earnings', 'total_deductions', 'net_pay'];
/** PayslipService: newest first. */
export const PAYSLIPS_DEFAULT_SORT = '-id';

export const PAYSLIPS_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    numbers: ['payroll_run_id', 'employee_id'],
    allowed: { sort: sortOptions(PAYSLIPS_SORT_FIELDS) },
};

export function payslipsServerFilters(params: PayslipListFilters): PayslipListFilters {
    return compactParams(params);
}

export function payslipsQueryKey(filters: PayslipListFilters) {
    return ['payroll', 'payslips', 'list', filters] as const;
}

/* --------------------------- salary components --------------------------- */

/** ListSalaryComponentsRequest::SORTABLE. */
export const COMPONENTS_SORT_FIELDS: readonly string[] = ['code', 'name', 'type', 'is_active'];
/** SalaryComponentService orders by name when nothing is asked. */
export const COMPONENTS_DEFAULT_SORT = 'name';

export const COMPONENTS_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: sortOptions(COMPONENTS_SORT_FIELDS) },
};

export function componentsServerFilters(params: SalaryComponentListFilters): SalaryComponentListFilters {
    return compactParams(params);
}

/** Under the ['payroll', 'salary-components'] prefix the create invalidates; the picker's key is ['payroll', 'salary-components', 'all']. */
export function componentsQueryKey(filters: SalaryComponentListFilters) {
    return ['payroll', 'salary-components', 'list', filters] as const;
}

/* --------------------------- salary structures --------------------------- */

/** ListSalaryStructuresRequest::SORTABLE — the structure's own dated column. */
export const STRUCTURES_SORT_FIELDS: readonly string[] = ['effective_from'];
/** SalaryStructureService: latest effective date first. */
export const STRUCTURES_DEFAULT_SORT = '-effective_from';

/** `employee_id` is the filter this index has always taken; it rides on the URL. */
export const STRUCTURES_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    numbers: ['employee_id'],
    allowed: { sort: sortOptions(STRUCTURES_SORT_FIELDS) },
};

export function structuresServerFilters(params: SalaryStructureListFilters): SalaryStructureListFilters {
    return compactParams(params);
}

export function structuresQueryKey(filters: SalaryStructureListFilters) {
    return ['payroll', 'salary-structures', 'list', filters] as const;
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
