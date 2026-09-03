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

/**
 * One day in the list of all marks — an applied record, or the uploaded day
 * no applied record covers. `id` is null for the second kind, which is why
 * the table keys on `key` and not on it.
 */
export interface Attendance {
    id: number | null;
    key: string;
    employee?: { id: number; name: string };
    date: string;
    /** Null when the reviewer has not answered the uploaded day yet. */
    status: AttendanceStatus | 'week_off' | null;
    check_in: string | null;
    check_out: string | null;
    notes: string | null;
    source: 'attendance' | 'import';
    needs_review: boolean;
    /** Read from a run nobody has applied — not merely from an upload. */
    provisional: boolean;
}

/** One person's range on the Attendance page: the days, and what they came to. */
/**
 * The caller's OWN range. Same shape as one person's, except that a login
 * with no employee row behind it has no person to name.
 */
export interface AttendanceMine extends Omit<AttendancePersonRange, 'employee'> {
    employee: AttendancePersonRange['employee'] | null;
}

export interface AttendancePersonRange {
    employee: {
        id: number;
        employee_code: string;
        name: string;
        department: string | null;
        designation: string | null;
    };
    from: string;
    to: string;
    days: {
        id: number | null;
        date: string;
        /** Null when the reviewer has not answered the day yet. */
        status: AttendanceStatus | 'week_off' | null;
        check_in: string | null;
        check_out: string | null;
        notes: string | null;
        /** Where the day came from: the applied record, or an upload. */
        source: 'attendance' | 'import';
        needs_review: boolean;
        /** Read from a run nobody has applied — not merely from an upload. */
        provisional: boolean;
    }[];
    summary: AttendanceTally;
}

/**
 * One count per status the master knows, plus the three the upload fallback
 * adds: a week off is not attendance, an unanswered day is not yet
 * anything, and `from_import` is how many of these days come from an upload
 * nobody has applied.
 */
export interface AttendanceTally {
    present: number;
    absent: number;
    half_day: number;
    on_leave: number;
    recorded: number;
    week_off: number;
    needs_review: number;
    from_import: number;
    /**
     * Days the punch report could not make sense of — a punch in with no
     * punch out, no punch at all, hours that do not add up. Counted whether
     * or not anybody has since answered them, which is what makes it
     * different from `needs_review`.
     */
    mismatches: number;
}

export interface AttendanceDepartmentRow extends AttendanceTally {
    department: string;
    employees: number;
    /** Present days over recorded days, a half day counting as half. */
    present_percent: number;
}

/** The management read: the factory's attendance for a range, by department. */
export interface AttendanceSummary {
    from: string;
    to: string;
    departments: AttendanceDepartmentRow[];
    totals: AttendanceTally & { employees: number; departments: number; present_percent: number };
    /** The uploads these numbers are partly read from, and whether applied. */
    imports: {
        id: number;
        file_name: string | null;
        status: 'review' | 'applied';
        period_from: string;
        period_to: string;
    }[];
    most_absent: {
        employee_id: number;
        employee_code: string;
        name: string;
        department: string | null;
        absent: number;
    }[];
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
