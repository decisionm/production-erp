import { api } from '@/lib/api';
import { compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type { PunchEmployee } from './punchReport';
import type {
    Attendance,
    AttendanceImport,
    AttendanceImportLine,
    AttendanceImportLineListParams,
    AttendanceImportListParams,
    AttendanceImportResolution,
    AttendanceListParams,
    Employee,
    EmployeeListParams,
    LeaveBalance,
    LeaveRequest,
    LeaveRequestListParams,
    LeaveType,
} from './types';

/**
 * ONE PAGE of the employee list, narrowed and paged by the SERVER. The
 * params are the URL's (useListParams); compacted so `{ q: '' }` and `{}`
 * are the same request and the same query key.
 */
export async function listEmployees(params: EmployeeListParams = {}): Promise<Paginated<Employee>> {
    const { data } = await api.get<Paginated<Employee>>('/hrms/employees', { params: compactParams(params) });
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllEmployees(): Promise<Paginated<Employee>> {
    const { data } = await api.get<Paginated<Employee>>('/hrms/employees', { params: { per_page: 1000 } });
    return data;
}

export interface CreateEmployeePayload {
    employee_code: string;
    name: string;
    email?: string;
    phone?: string;
    date_of_birth?: string;
    date_of_joining: string;
    designation?: string;
    department?: string;
    manager_id?: number;
}

export async function createEmployee(payload: CreateEmployeePayload): Promise<Employee> {
    const { data } = await api.post<{ data: Employee }>('/hrms/employees', payload);
    return data.data;
}

export type UpdateEmployeePayload = Partial<CreateEmployeePayload> & { status?: Employee['status'] };

export async function updateEmployee(id: number, payload: UpdateEmployeePayload): Promise<Employee> {
    const { data } = await api.put<{ data: Employee }>(`/hrms/employees/${id}`, payload);
    return data.data;
}

export async function listLeaveTypes(): Promise<Paginated<LeaveType>> {
    const { data } = await api.get<Paginated<LeaveType>>('/hrms/leave-types');
    return data;
}

/**
 * Full reference list for a PICKER (all rows, not the default first page).
 * A dropdown that offers active rows only must be given every row to filter,
 * or it hides part of a list that was already truncated.
 */
export async function listAllLeaveTypes(): Promise<Paginated<LeaveType>> {
    const { data } = await api.get<Paginated<LeaveType>>('/hrms/leave-types', { params: { per_page: 1000 } });
    return data;
}

export interface CreateLeaveTypePayload {
    code: string;
    name: string;
    default_annual_days: number;
}

export async function createLeaveType(payload: CreateLeaveTypePayload): Promise<LeaveType> {
    const { data } = await api.post<{ data: LeaveType }>('/hrms/leave-types', payload);
    return data.data;
}

export type UpdateLeaveTypePayload = Partial<CreateLeaveTypePayload> & { is_active?: boolean };

export async function updateLeaveType(id: number, payload: UpdateLeaveTypePayload): Promise<LeaveType> {
    const { data } = await api.put<{ data: LeaveType }>(`/hrms/leave-types/${id}`, payload);
    return data.data;
}

export async function listLeaveBalances(): Promise<Paginated<LeaveBalance>> {
    const { data } = await api.get<Paginated<LeaveBalance>>('/hrms/leave-balances');
    return data;
}

export interface AllocateLeaveBalancePayload {
    employee_id: number;
    leave_type_id: number;
    year: number;
    allocated_days?: number;
}

export async function allocateLeaveBalance(payload: AllocateLeaveBalancePayload): Promise<LeaveBalance> {
    const { data } = await api.post<{ data: LeaveBalance }>('/hrms/leave-balances', payload);
    return data.data;
}

/** One page of leave requests, narrowed and paged by the server. */
export async function listLeaveRequests(params: LeaveRequestListParams = {}): Promise<Paginated<LeaveRequest>> {
    const { data } = await api.get<Paginated<LeaveRequest>>('/hrms/leave-requests', { params: compactParams(params) });
    return data;
}

export interface CreateLeaveRequestPayload {
    employee_id: number;
    leave_type_id: number;
    start_date: string;
    end_date: string;
    days: number;
    reason?: string;
}

export async function createLeaveRequest(payload: CreateLeaveRequestPayload): Promise<LeaveRequest> {
    const { data } = await api.post<{ data: LeaveRequest }>('/hrms/leave-requests', payload);
    return data.data;
}

export async function approveLeaveRequest(id: number): Promise<LeaveRequest> {
    const { data } = await api.post<{ data: LeaveRequest }>(`/hrms/leave-requests/${id}/approve`);
    return data.data;
}

export async function rejectLeaveRequest(id: number): Promise<LeaveRequest> {
    const { data } = await api.post<{ data: LeaveRequest }>(`/hrms/leave-requests/${id}/reject`);
    return data.data;
}

/** One page of attendance marks, narrowed and paged by the server. */
export async function listAttendance(params: AttendanceListParams = {}): Promise<Paginated<Attendance>> {
    const { data } = await api.get<Paginated<Attendance>>('/hrms/attendance', { params: compactParams(params) });
    return data;
}

export interface MarkAttendancePayload {
    employee_id: number;
    date: string;
    status: Attendance['status'];
    notes?: string;
}

export async function markAttendance(payload: MarkAttendancePayload): Promise<Attendance> {
    const { data } = await api.post<{ data: Attendance }>('/hrms/attendance/mark', payload);
    return data.data;
}

// ---- the punch-report import (03-Sep design, Track 2) --------------------

/** One page of import runs, newest first, narrowed and paged by the server. */
export async function listAttendanceImports(params: AttendanceImportListParams = {}): Promise<Paginated<AttendanceImport>> {
    const { data } = await api.get<Paginated<AttendanceImport>>('/hrms/attendance-imports', { params: compactParams(params) });
    return data;
}

export async function getAttendanceImport(id: number): Promise<AttendanceImport> {
    const { data } = await api.get<{ data: AttendanceImport }>(`/hrms/attendance-imports/${id}`);
    return data.data;
}

export interface CreateAttendanceImportPayload {
    period_from: string;
    period_to: string;
    source: 'pooja';
    file_name?: string | null;
    employees: PunchEmployee[];
}

/** The parsed workbook, as rows — no file leaves the browser. */
export async function createAttendanceImport(payload: CreateAttendanceImportPayload): Promise<AttendanceImport> {
    const { data } = await api.post<{ data: AttendanceImport }>('/hrms/attendance-imports', payload);
    return data.data;
}

/** One page of a run's lines, open issues first, narrowed and paged by the server. */
export async function listAttendanceImportLines(
    id: number,
    params: AttendanceImportLineListParams = {},
): Promise<Paginated<AttendanceImportLine>> {
    const { data } = await api.get<Paginated<AttendanceImportLine>>(`/hrms/attendance-imports/${id}/lines`, {
        params: compactParams(params),
    });
    return data;
}

export interface ResolveAttendanceImportLinePayload {
    resolution: AttendanceImportResolution;
    check_in?: string | null;
    check_out?: string | null;
    notes?: string | null;
}

/** The reviewer's answer for one employee-day; the server writes attendances at once. */
export async function resolveAttendanceImportLine(
    id: number,
    lineId: number,
    payload: ResolveAttendanceImportLinePayload,
): Promise<AttendanceImportLine> {
    const { data } = await api.patch<{ data: AttendanceImportLine }>(`/hrms/attendance-imports/${id}/lines/${lineId}`, payload);
    return data.data;
}

/** Every clean and answered line into attendances; a 422 with the open count otherwise. */
export async function applyAttendanceImport(id: number): Promise<AttendanceImport> {
    const { data } = await api.post<{ data: AttendanceImport }>(`/hrms/attendance-imports/${id}/apply`);
    return data.data;
}
