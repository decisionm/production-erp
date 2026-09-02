import type { ListParamsSpec } from '@/lib/listParams';
export { noMatchLine, pageRangeLine } from '@/lib/tableProps';
import type { AttendanceStatus, EmployeeStatus, LeaveRequestStatus } from './types';

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


