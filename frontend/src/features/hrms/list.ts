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
 * THE THREE HRMS LISTS' SHAPE ON THE URL — what each page's useListParams
 * reads and writes — plus the two lines every one of them says.
 *
 * Each spec is a module-level constant on purpose (useListParams memoises
 * on it). Anything not named here is dropped on read, so a stray key never
 * reaches the server through the list; a status the master does not know
 * is dropped too, so a stale link cannot 422 the page on load.
 */

export const EMPLOYEE_STATUSES: readonly EmployeeStatus[] = ['active', 'inactive', 'terminated'];
export const LEAVE_REQUEST_STATUSES: readonly LeaveRequestStatus[] = ['pending', 'approved', 'rejected'];
export const ATTENDANCE_STATUSES: readonly AttendanceStatus[] = ['present', 'absent', 'half_day', 'on_leave'];

export const EMPLOYEE_LIST_SPEC: ListParamsSpec = {
    strings: ['status'],
    allowed: { status: EMPLOYEE_STATUSES },
};

export const LEAVE_REQUEST_LIST_SPEC: ListParamsSpec = {
    strings: ['status'],
    numbers: ['employee_id'],
    allowed: { status: LEAVE_REQUEST_STATUSES },
};

/** `from` / `to` ride as typed; the server refuses a non-date or a reversed range. */
export const ATTENDANCE_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'from', 'to'],
    numbers: ['employee_id'],
    allowed: { status: ATTENDANCE_STATUSES },
};

// ---- the punch-report import (03-Sep design, Track 2) --------------------

export const ATTENDANCE_IMPORT_LINE_FILTERS: readonly AttendanceImportLineFilter[] = [
    'open',
    'in_no_out',
    'out_no_in',
    'no_punch',
    'unknown_employee',
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

    return line.issue === 'no_punch' ? 'absent' : 'present';
}

/** "Apply" once nothing is open; "Apply (3 open)" — and disabled — until then. */
export function applyLabel(openCount: number): string {
    return openCount === 0 ? 'Apply' : `Apply (${openCount} open)`;
}


