import type { ConfigurationAbilities } from '@/components/configuration';

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
