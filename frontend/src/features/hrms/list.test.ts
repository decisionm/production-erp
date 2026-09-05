import { describe, expect, it } from 'vitest';
import { compactParams, readListParams } from '@/lib/listParams';
import {
    ATTENDANCE_DEFAULT_SORT,
    ATTENDANCE_LIST_SPEC,
    ATTENDANCE_SORT_FIELDS,
    EMPLOYEE_DEFAULT_SORT,
    EMPLOYEE_LIST_SPEC,
    EMPLOYEE_SORT_FIELDS,
    LEAVE_BALANCE_DEFAULT_SORT,
    LEAVE_BALANCE_LIST_SPEC,
    LEAVE_BALANCE_SORT_FIELDS,
    LEAVE_REQUEST_DEFAULT_SORT,
    LEAVE_REQUEST_LIST_SPEC,
    LEAVE_REQUEST_SORT_FIELDS,
    LEAVE_TYPE_DEFAULT_SORT,
    LEAVE_TYPE_LIST_SPEC,
    LEAVE_TYPE_SORT_FIELDS,
} from './list';
import type { ListParamsSpec } from '@/lib/listParams';

/**
 * THE FIVE HRMS LISTS' SORT ON THE URL (03-Sep-2026). What each page hands
 * the server is `compactParams(readListParams(url, SPEC))` — pinned here so
 * that:
 *
 *   - a `sort` the server would 422 is dropped at the door, and the page
 *     loads in its default order instead of failing on a stale link;
 *   - a `sort` the server knows reaches the request, in the server's own
 *     spelling, beside the filters the page already carried;
 *   - each DEFAULT_SORT is the order the matching service uses when nothing
 *     is asked — the header arrow must show the order the page loaded in.
 */

function read(query: string, spec: ListParamsSpec) {
    return compactParams(readListParams(new URLSearchParams(query), spec));
}

const LISTS: Array<{ name: string; spec: ListParamsSpec; fields: readonly string[]; defaultSort: string; serviceDefault: string }> = [
    { name: 'employees', spec: EMPLOYEE_LIST_SPEC, fields: EMPLOYEE_SORT_FIELDS, defaultSort: EMPLOYEE_DEFAULT_SORT, serviceDefault: 'name' },
    { name: 'attendance', spec: ATTENDANCE_LIST_SPEC, fields: ATTENDANCE_SORT_FIELDS, defaultSort: ATTENDANCE_DEFAULT_SORT, serviceDefault: '-date' },
    { name: 'leave requests', spec: LEAVE_REQUEST_LIST_SPEC, fields: LEAVE_REQUEST_SORT_FIELDS, defaultSort: LEAVE_REQUEST_DEFAULT_SORT, serviceDefault: '-id' },
    { name: 'leave types', spec: LEAVE_TYPE_LIST_SPEC, fields: LEAVE_TYPE_SORT_FIELDS, defaultSort: LEAVE_TYPE_DEFAULT_SORT, serviceDefault: 'name' },
    { name: 'leave balances', spec: LEAVE_BALANCE_LIST_SPEC, fields: LEAVE_BALANCE_SORT_FIELDS, defaultSort: LEAVE_BALANCE_DEFAULT_SORT, serviceDefault: '-year' },
];

describe.each(LISTS)('the $name list', ({ spec, fields, defaultSort, serviceDefault }) => {
    it('drops a sort the server does not know, so a stale link loads the default order', () => {
        expect(read('sort=nonsense', spec)).toEqual({});
        expect(read('sort=--id', spec)).toEqual({});
        expect(read('sort=manager', spec)).toEqual({});
        expect(read('sort=', spec)).toEqual({});
    });

    it('carries every sortable column, bare and descending, to the server', () => {
        for (const field of fields) {
            expect(read(`sort=${field}`, spec)).toEqual({ sort: field });
            expect(read(`sort=-${field}`, spec)).toEqual({ sort: `-${field}` });
        }
    });

    it('defaults to the order the service uses when nothing is asked', () => {
        expect(defaultSort).toBe(serviceDefault);
        expect(read('', spec).sort).toBeUndefined();
    });
});

describe('the sort rides beside the filters each page already carried', () => {
    it('employees: status and search', () => {
        expect(read('q=stores&status=active&sort=-date_of_joining&page=2', EMPLOYEE_LIST_SPEC)).toEqual({
            q: 'stores',
            status: 'active',
            sort: '-date_of_joining',
            page: 2,
        });
    });

    it('attendance: status, employee and the date range', () => {
        expect(read('status=present&employee_id=7&from=2026-08-01&to=2026-08-31&sort=date', ATTENDANCE_LIST_SPEC)).toEqual({
            status: 'present',
            employee_id: 7,
            from: '2026-08-01',
            to: '2026-08-31',
            sort: 'date',
        });
    });

    it('leave requests: status and employee', () => {
        expect(read('status=pending&employee_id=3&sort=-days', LEAVE_REQUEST_LIST_SPEC)).toEqual({
            status: 'pending',
            employee_id: 3,
            sort: '-days',
        });
    });

    it('leave balances: remaining is computed, not a column, so it is never sent', () => {
        expect(read('sort=remaining_days', LEAVE_BALANCE_LIST_SPEC)).toEqual({});
        expect(LEAVE_BALANCE_SORT_FIELDS).not.toContain('remaining_days');
        // Accrued is allocated − opening, computed in the resource the same way.
        expect(read('sort=accrued_days', LEAVE_BALANCE_LIST_SPEC)).toEqual({});
        expect(LEAVE_BALANCE_SORT_FIELDS).not.toContain('accrued_days');
    });

    it('the sortable columns are exactly the ones the List*Requests accept', () => {
        expect(EMPLOYEE_SORT_FIELDS).toEqual(['employee_code', 'name', 'designation', 'department', 'date_of_joining', 'status']);
        expect(ATTENDANCE_SORT_FIELDS).toEqual(['date', 'status']);
        expect(LEAVE_REQUEST_SORT_FIELDS).toEqual(['start_date', 'end_date', 'days', 'status']);
        expect(LEAVE_TYPE_SORT_FIELDS).toEqual(['code', 'name', 'default_annual_days', 'monthly_accrual_days', 'is_active']);
        expect(LEAVE_BALANCE_SORT_FIELDS).toEqual(['year', 'opening_days', 'allocated_days', 'used_days']);
    });
});
