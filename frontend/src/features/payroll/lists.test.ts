import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import {
    COMPONENTS_DEFAULT_SORT,
    COMPONENTS_LIST_SPEC,
    COMPONENTS_SORT_FIELDS,
    PAYSLIPS_DEFAULT_SORT,
    PAYSLIPS_LIST_SPEC,
    PAYSLIPS_SORT_FIELDS,
    RUNS_DEFAULT_SORT,
    RUNS_LIST_SPEC,
    RUNS_SORT_FIELDS,
    STRUCTURES_DEFAULT_SORT,
    STRUCTURES_LIST_SPEC,
    STRUCTURES_SORT_FIELDS,
    componentsQueryKey,
    componentsServerFilters,
    payslipsServerFilters,
    runsServerFilters,
    structuresQueryKey,
    structuresServerFilters,
} from './lists';
import type { ListParams, ListParamsSpec } from '@/lib/listParams';

/**
 * THE FOUR PAYROLL LISTS' SORT ON THE URL (03-Sep-2026). Each page hands
 * the server `<list>ServerFilters(readListParams(url, SPEC))` — pinned here
 * so that a `sort` the server would 422 is dropped at the door, a known one
 * reaches the request in the server's own spelling beside the filters the
 * page already carried, and each DEFAULT_SORT is the order the matching
 * service uses when nothing is asked.
 */

function read(query: string, spec: ListParamsSpec): ListParams {
    return readListParams(new URLSearchParams(query), spec);
}

const LISTS: Array<{
    name: string;
    spec: ListParamsSpec;
    fields: readonly string[];
    defaultSort: string;
    serviceDefault: string;
    toServer: (params: ListParams) => ListParams;
}> = [
    { name: 'runs', spec: RUNS_LIST_SPEC, fields: RUNS_SORT_FIELDS, defaultSort: RUNS_DEFAULT_SORT, serviceDefault: '-period', toServer: runsServerFilters },
    { name: 'payslips', spec: PAYSLIPS_LIST_SPEC, fields: PAYSLIPS_SORT_FIELDS, defaultSort: PAYSLIPS_DEFAULT_SORT, serviceDefault: '-id', toServer: payslipsServerFilters },
    { name: 'components', spec: COMPONENTS_LIST_SPEC, fields: COMPONENTS_SORT_FIELDS, defaultSort: COMPONENTS_DEFAULT_SORT, serviceDefault: 'name', toServer: componentsServerFilters },
    { name: 'structures', spec: STRUCTURES_LIST_SPEC, fields: STRUCTURES_SORT_FIELDS, defaultSort: STRUCTURES_DEFAULT_SORT, serviceDefault: '-effective_from', toServer: structuresServerFilters },
];

describe.each(LISTS)('the $name list', ({ spec, fields, defaultSort, serviceDefault, toServer }) => {
    it('drops a sort the server does not know, so a stale link loads the default order', () => {
        expect(toServer(read('sort=nonsense', spec))).toEqual({});
        expect(toServer(read('sort=--id', spec))).toEqual({});
        expect(toServer(read('sort=employee', spec))).toEqual({});
        expect(toServer(read('sort=', spec))).toEqual({});
    });

    it('carries every sortable column, bare and descending, to the server', () => {
        for (const field of fields) {
            expect(toServer(read(`sort=${field}`, spec))).toEqual({ sort: field });
            expect(toServer(read(`sort=-${field}`, spec))).toEqual({ sort: `-${field}` });
        }
    });

    it('defaults to the order the service uses when nothing is asked', () => {
        expect(defaultSort).toBe(serviceDefault);
        expect(toServer(read('', spec)).sort).toBeUndefined();
    });
});

describe('the sort rides beside the filters each page already carried', () => {
    it('runs: the period search and the status', () => {
        expect(runsServerFilters(read('q=aug%202026&status=paid&sort=-paid_at&page=3', RUNS_LIST_SPEC))).toEqual({
            q: 'aug 2026',
            status: 'paid',
            sort: '-paid_at',
            page: 3,
        });
        // year and month are one sort, not two: neither is sent on its own.
        expect(runsServerFilters(read('sort=year', RUNS_LIST_SPEC))).toEqual({});
        expect(runsServerFilters(read('sort=-month', RUNS_LIST_SPEC))).toEqual({});
    });

    it('payslips: the run the runs page links to, the employee and the search', () => {
        expect(payslipsServerFilters(read('payroll_run_id=7&employee_id=2&q=ani&sort=-net_pay', PAYSLIPS_LIST_SPEC))).toEqual({
            payroll_run_id: 7,
            employee_id: 2,
            q: 'ani',
            sort: '-net_pay',
        });
    });

    it('structures: the employee filter the index has always taken', () => {
        const filters = structuresServerFilters(read('employee_id=5&sort=effective_from&per_page=50', STRUCTURES_LIST_SPEC));
        expect(filters).toEqual({ employee_id: 5, sort: 'effective_from', per_page: 50 });
        expect(structuresQueryKey(filters)).toEqual(['payroll', 'salary-structures', 'list', filters]);
    });

    it('components: the key stays under the prefix the create invalidates, apart from the picker', () => {
        expect(componentsQueryKey({})).toEqual(['payroll', 'salary-components', 'list', {}]);
        expect(componentsQueryKey({})).not.toEqual(['payroll', 'salary-components', 'all']);
    });

    it('the sortable columns are exactly the ones the List*Requests accept', () => {
        expect(RUNS_SORT_FIELDS).toEqual(['period', 'status', 'processed_at', 'paid_at']);
        expect(PAYSLIPS_SORT_FIELDS).toEqual(['gross_earnings', 'total_deductions', 'net_pay']);
        expect(COMPONENTS_SORT_FIELDS).toEqual(['code', 'name', 'type', 'is_active']);
        expect(STRUCTURES_SORT_FIELDS).toEqual(['effective_from']);
    });
});
