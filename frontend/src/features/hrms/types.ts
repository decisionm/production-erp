import type { ConfigurationAbilities } from '@/components/configuration';
import type { ListParams } from '@/lib/listParams';

export type EmployeeStatus = 'active' | 'inactive' | 'terminated';

export interface Employee {
    id: number;
    employee_code: string;
    name: string;
    email: string | null;
    phone: string | null;
    date_of_birth: string | null;
    date_of_joining: string;
    designation: string | null;
    department: string | null;
    status: EmployeeStatus;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Optional: absent on a backend that predates the wiring, and the row
     * actions then offer nothing rather than invent permission. `delete:
     * null` on an index row means UNDETERMINED — the confirm asks.
     */
    can?: ConfigurationAbilities | null;
    manager?: { id: number; name: string };
    created_at: string;
}

export interface LeaveType {
    id: number;
    code: string;
    name: string;
    default_annual_days: string;
    is_active: boolean;
    created_at: string;
}

export interface LeaveBalance {
    id: number;
    employee?: { id: number; name: string };
    leave_type: LeaveType;
    year: number;
    allocated_days: string;
    used_days: string;
    remaining_days: string;
}

export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected';

export interface LeaveRequest {
    id: number;
    employee?: { id: number; name: string };
    leave_type: LeaveType;
    start_date: string;
    end_date: string;
    days: string;
    reason: string | null;
    status: LeaveRequestStatus;
    approved_by: string | null;
    decided_at: string | null;
    created_at: string;
}

export type AttendanceStatus = 'present' | 'absent' | 'half_day' | 'on_leave';

export interface Attendance {
    id: number;
    employee?: { id: number; name: string };
    date: string;
    status: AttendanceStatus;
    check_in: string | null;
    check_out: string | null;
    notes: string | null;
}

// ---- the punch-report import (03-Sep design, Track 2) --------------------

export type AttendanceImportStatus = 'review' | 'applied';

export type AttendanceImportIssue =
    | 'in_no_out'
    | 'out_no_in'
    | 'no_punch'
    | 'unknown_employee'
    | 'hours_unclear'
    | 'worked_on_week_off';

/** The four attendance statuses plus week_off, which stays on the line. */
export type AttendanceImportResolution = AttendanceStatus | 'week_off';

/** The review chips' numbers, counted by the server on every read. */
export interface AttendanceImportCounts {
    open: number;
    in_no_out: number;
    out_no_in: number;
    no_punch: number;
    unknown_employee: number;
    hours_unclear: number;
    worked_on_week_off: number;
    /** Days a person answered where the punch app has since changed its own figures. */
    report_changed: number;
    resolved: number;
    clean: number;
}

export interface AttendanceImport {
    id: number;
    source: string;
    period_from: string;
    period_to: string;
    file_name: string | null;
    status: AttendanceImportStatus;
    employee_count: number;
    day_count: number;
    issue_count: number;
    open_count: number;
    counts: AttendanceImportCounts;
    uploaded_by?: { id: number; name: string };
    applied_at: string | null;
    created_at: string;
}

/** One employee-day. Times are the report's wall-clock HH:MM (IST). */
export interface AttendanceImportLine {
    id: number;
    attendance_import_id: number;
    employee_id: number | null;
    employee_code: string;
    employee_name: string;
    employee?: { id: number; name: string; department: string | null; designation: string | null };
    date: string;
    raw_status: string;
    first_in: string | null;
    last_out: string | null;
    ot_minutes: number;
    late_minutes: number;
    early_minutes: number;
    worked_minutes: number;
    issue: AttendanceImportIssue | null;
    resolution: AttendanceImportResolution | null;
    resolved_check_in: string | null;
    resolved_check_out: string | null;
    resolved_by?: { id: number; name: string };
    resolved_at: string | null;
    /** Set when the report moved under an answer somebody had already given. */
    report_changed_at: string | null;
    notes: string | null;
    applied_at: string | null;
}

/** The review list's `issue` chip — ListAttendanceImportLinesRequest's values. */
export type AttendanceImportLineFilter = 'open' | AttendanceImportIssue | 'resolved' | 'clean' | 'report_changed';

/**
 * What one square of the month strip draws: the answer a day carries, or
 * the fact that it carries none yet. `needs_fix` is the only state that
 * asks anything of the reviewer.
 */
export type DayState = AttendanceImportResolution | 'needs_fix';

/** One person's month in a run — the review's other grain. */
export interface AttendanceImportEmployee {
    employee_code: string;
    employee_name: string;
    employee_id: number | null;
    /** False when the report's code is not in the employee master. */
    known: boolean;
    department: string | null;
    designation: string | null;
    day_count: number;
    open_count: number;
    resolved_count: number;
    clean_count: number;
    days: { date: string; state: DayState }[];
}

/** What one bulk answer did, skips named rather than counted away. */
export interface BulkResolveResult {
    resolved: number;
    skipped: number;
    skipped_codes: string[];
    import: AttendanceImport;
}

export type AttendanceImportEmployeeListParams = ListParams;

export type AttendanceImportListParams = ListParams;

export type AttendanceImportLineListParams = ListParams & { issue?: AttendanceImportLineFilter };

/**
 * The five lists' query strings — exactly what the server's List*Request
 * classes validate. `q`, `page` and `per_page` come with ListParams; `sort`
 * is the server's spelling (`name`, `-date`); the rest is each list's own.
 * `from` / `to` are Y-m-d on the attendance DATE.
 */
export type EmployeeListParams = ListParams & { status?: EmployeeStatus; sort?: string };

export type LeaveRequestListParams = ListParams & { status?: LeaveRequestStatus; employee_id?: number; sort?: string };

export type AttendanceListParams = ListParams & {
    status?: AttendanceStatus;
    employee_id?: number;
    from?: string;
    to?: string;
    sort?: string;
};

export type LeaveTypeListParams = ListParams & { sort?: string };

export type LeaveBalanceListParams = ListParams & { sort?: string };
