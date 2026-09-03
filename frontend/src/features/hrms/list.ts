import type { ListParamsSpec } from '@/lib/listParams';
export { noMatchLine, pageRangeLine } from '@/lib/tableProps';
import type {
    AttendanceImportCounts,
    AttendanceImportIssue,
    AttendanceImportLine,
    AttendanceImportLineFilter,
    AttendanceImportResolution,
    AttendanceStatus,
    EmployeeStatus,
    LeaveRequestStatus,
} from './types';

/**
 * THE FIVE HRMS LISTS' SHAPE ON THE URL — what each page's useListParams
 * reads and writes — plus the two lines every one of them says.
 *
 * Each spec is a module-level constant on purpose (useListParams memoises
 * on it). Anything not named here is dropped on read, so a stray key never
 * reaches the server through the list; a status the master does not know
 * is dropped too, so a stale link cannot 422 the page on load.
 *
 * `sort` (03-Sep-2026) is the server's own spelling — a bare column for
 * ascending, `-column` for descending, absent for the list's default — and
 * only a column the matching List*Request sorts on is let through, so a
 * mistyped link falls back to the default order rather than a 422. Each
 * DEFAULT_SORT is what the service orders by when nothing is asked, so the
 * header arrow shows the order the page actually loaded in.
 */

export const EMPLOYEE_STATUSES: readonly EmployeeStatus[] = ['active', 'inactive', 'terminated'];
export const LEAVE_REQUEST_STATUSES: readonly LeaveRequestStatus[] = ['pending', 'approved', 'rejected'];
export const ATTENDANCE_STATUSES: readonly AttendanceStatus[] = ['present', 'absent', 'half_day', 'on_leave'];

/** Bare and "-" prefixed: the two spellings the URL's `sort` may take per column. */
function sortOptions(fields: readonly string[]): string[] {
    return fields.flatMap((field) => [field, `-${field}`]);
}

/* ------------------------------- employees ------------------------------- */

/** ListEmployeesRequest::SORTABLE — every one a column the table shows. */
export const EMPLOYEE_SORT_FIELDS: readonly string[] = ['employee_code', 'name', 'designation', 'department', 'date_of_joining', 'status'];
/** EmployeeService orders by name when nothing is asked. */
export const EMPLOYEE_DEFAULT_SORT = 'name';

export const EMPLOYEE_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'sort'],
    allowed: { status: EMPLOYEE_STATUSES, sort: sortOptions(EMPLOYEE_SORT_FIELDS) },
};

/* ----------------------------- leave requests ---------------------------- */

/** ListLeaveRequestsRequest::SORTABLE — the request's own dates, days and status. */
export const LEAVE_REQUEST_SORT_FIELDS: readonly string[] = ['start_date', 'end_date', 'days', 'status'];
/** LeaveRequestService: newest first. */
export const LEAVE_REQUEST_DEFAULT_SORT = '-id';

export const LEAVE_REQUEST_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'sort'],
    numbers: ['employee_id'],
    allowed: { status: LEAVE_REQUEST_STATUSES, sort: sortOptions(LEAVE_REQUEST_SORT_FIELDS) },
};

/* -------------------------------- attendance ----------------------------- */

/** ListAttendanceRequest::SORTABLE. */
export const ATTENDANCE_SORT_FIELDS: readonly string[] = ['date', 'status'];
/** AttendanceService: newest date first. */
export const ATTENDANCE_DEFAULT_SORT = '-date';

/** `from` / `to` ride as typed; the server refuses a non-date or a reversed range. */
export const ATTENDANCE_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'from', 'to', 'sort'],
    numbers: ['employee_id'],
    allowed: { status: ATTENDANCE_STATUSES, sort: sortOptions(ATTENDANCE_SORT_FIELDS) },
};

// ---- the punch-report import (03-Sep design, Track 2) --------------------

export const ATTENDANCE_IMPORT_LINE_FILTERS: readonly AttendanceImportLineFilter[] = [
    'open',
    'in_no_out',
    'out_no_in',
    'no_punch',
    'unknown_employee',
    'hours_unclear',
    'worked_on_week_off',
    'report_changed',
    'resolved',
    'clean',
];

/** Only `q`, `page`, `per_page` — runs are searched by period or file name. */
export const ATTENDANCE_IMPORT_LIST_SPEC: ListParamsSpec = {};

export const ATTENDANCE_IMPORT_LINE_LIST_SPEC: ListParamsSpec = {
    strings: ['issue'],
    allowed: { issue: ATTENDANCE_IMPORT_LINE_FILTERS },
};

export const ISSUE_LABELS: Record<AttendanceImportIssue, string> = {
    in_no_out: 'In without Out',
    out_no_in: 'Out without In',
    no_punch: 'No punch',
    unknown_employee: 'Unknown employee',
    hours_unclear: 'Hours do not add up',
    worked_on_week_off: 'Worked on a week off',
};

export const RESOLUTION_LABELS: Record<AttendanceImportResolution, string> = {
    present: 'Present',
    half_day: 'Half Day',
    absent: 'Absent',
    on_leave: 'On Leave',
    week_off: 'Week Off',
};

/**
 * The review chips, each with its server count: All issues, one per kind,
 * Resolved, Clean — plus All (no chip) as the bare list. Pure, so the
 * render test can pin the numbers beside the labels.
 */
export function lineFilterChips(
    counts: AttendanceImportCounts | undefined,
): { value: AttendanceImportLineFilter | ''; label: string }[] {
    const n = (key: keyof AttendanceImportCounts) => (counts ? ` (${counts[key]})` : '');

    return [
        { value: '', label: 'All' },
        { value: 'open', label: `All issues${n('open')}` },
        { value: 'in_no_out', label: `${ISSUE_LABELS.in_no_out}${n('in_no_out')}` },
        { value: 'out_no_in', label: `${ISSUE_LABELS.out_no_in}${n('out_no_in')}` },
        { value: 'no_punch', label: `${ISSUE_LABELS.no_punch}${n('no_punch')}` },
        { value: 'unknown_employee', label: `${ISSUE_LABELS.unknown_employee}${n('unknown_employee')}` },
        { value: 'hours_unclear', label: `${ISSUE_LABELS.hours_unclear}${n('hours_unclear')}` },
        { value: 'worked_on_week_off', label: `${ISSUE_LABELS.worked_on_week_off}${n('worked_on_week_off')}` },
        { value: 'report_changed', label: `Report changed${n('report_changed')}` },
        { value: 'resolved', label: `Resolved${n('resolved')}` },
        { value: 'clean', label: `Clean${n('clean')}` },
    ];
}

/**
 * What the correction modal opens on: a line's own answer if it has one,
 * else the spec's default for its issue — absent for a missing punch,
 * present for a half-recorded day.
 */
export function defaultResolution(line: Pick<AttendanceImportLine, 'issue' | 'resolution'>): AttendanceImportResolution {
    if (line.resolution) return line.resolution;

    // A day nobody punched opens on Absent; a week off somebody worked
    // opens on Present, since they were here; everything else on Present.
    return line.issue === 'no_punch' ? 'absent' : 'present';
}

/** "Apply" once nothing is open; "Apply (3 open)" — and disabled — until then. */
export function applyLabel(openCount: number): string {
    return openCount === 0 ? 'Apply' : `Apply (${openCount} open)`;
}

/* ------------------------------- leave types ----------------------------- */

/** ListLeaveTypesRequest::SORTABLE. */
export const LEAVE_TYPE_SORT_FIELDS: readonly string[] = ['code', 'name', 'default_annual_days', 'is_active'];
/** LeaveTypeService orders by name when nothing is asked. */
export const LEAVE_TYPE_DEFAULT_SORT = 'name';

export const LEAVE_TYPE_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: sortOptions(LEAVE_TYPE_SORT_FIELDS) },
};

/* ------------------------------ leave balances --------------------------- */

/** ListLeaveBalancesRequest::SORTABLE — the stored figures; remaining is computed and not sortable. */
export const LEAVE_BALANCE_SORT_FIELDS: readonly string[] = ['year', 'allocated_days', 'used_days'];
/** LeaveBalanceService: newest year first. */
export const LEAVE_BALANCE_DEFAULT_SORT = '-year';

export const LEAVE_BALANCE_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: sortOptions(LEAVE_BALANCE_SORT_FIELDS) },
};
