import { api } from '@/lib/api';
import { MAX_PER_PAGE, compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type {
    PayrollRun,
    PayrollRunListFilters,
    Payslip,
    PayslipListFilters,
    SalaryCalculationType,
    SalaryComponent,
    SalaryComponentKind,
    SalaryComponentListFilters,
    SalaryStructure,
    SalaryStructureListFilters,
    SkippedEmployee,
} from './types';

/** ONE page of the component master, sorted and paged on the SERVER (ListSalaryComponentsRequest). */
export async function listSalaryComponents(filters: SalaryComponentListFilters = {}): Promise<Paginated<SalaryComponent>> {
    const { data } = await api.get<Paginated<SalaryComponent>>('/payroll/salary-components', { params: compactParams(filters) });
    return data;
}

/**
 * Full reference list for a PICKER (all rows, not the default first page).
 * A dropdown that offers active rows only must be given every row to filter,
 * or it hides part of a list that was already truncated.
 */
export async function listAllSalaryComponents(): Promise<Paginated<SalaryComponent>> {
    const { data } = await api.get<Paginated<SalaryComponent>>('/payroll/salary-components', { params: { per_page: 1000 } });
    return data;
}

export interface CreateSalaryComponentPayload {
    code: string;
    name: string;
    type: SalaryComponentKind;
    calculation_type: SalaryCalculationType;
    percentage?: number;
}

export async function createSalaryComponent(payload: CreateSalaryComponentPayload): Promise<SalaryComponent> {
    const { data } = await api.post<{ data: SalaryComponent }>('/payroll/salary-components', payload);
    return data.data;
}

/** ONE page of structures, filtered, sorted and paged on the SERVER (ListSalaryStructuresRequest). */
export async function listSalaryStructures(filters: SalaryStructureListFilters = {}): Promise<Paginated<SalaryStructure>> {
    const { data } = await api.get<Paginated<SalaryStructure>>('/payroll/salary-structures', { params: compactParams(filters) });
    return data;
}

export interface CreateSalaryStructurePayload {
    employee_id: number;
    effective_from: string;
    lines: { salary_component_id: number; amount?: number }[];
}

export async function createSalaryStructure(payload: CreateSalaryStructurePayload): Promise<SalaryStructure> {
    const { data } = await api.post<{ data: SalaryStructure }>('/payroll/salary-structures', payload);
    return data.data;
}

/**
 * ONE page of the runs, narrowed and paged on the SERVER (PayrollRunService
 * through ListPayrollRunsRequest). The Payroll Runs table used to call this
 * with no arguments and draw the answer with `pagination={false}`, so it
 * showed the server's first 20 and said nothing about the rest. Compacted:
 * `{}` and `{ q: '' }` are the same request and the same query key.
 */
export async function listPayrollRuns(filters: PayrollRunListFilters = {}): Promise<Paginated<PayrollRun>> {
    const { data } = await api.get<Paginated<PayrollRun>>('/payroll/runs', { params: compactParams(filters) });
    return data;
}

/**
 * Every run, for a PICKER (the payslip page's run filter) — the server's
 * ceiling, not the default first page, so a run older than the newest 20
 * is still offered.
 */
export async function listAllPayrollRuns(): Promise<Paginated<PayrollRun>> {
    const { data } = await api.get<Paginated<PayrollRun>>('/payroll/runs', { params: { per_page: MAX_PER_PAGE } });
    return data;
}

export interface CreatePayrollRunPayload {
    year: number;
    month: number;
}

export async function createPayrollRun(payload: CreatePayrollRunPayload): Promise<PayrollRun> {
    const { data } = await api.post<{ data: PayrollRun }>('/payroll/runs', payload);
    return data.data;
}

export interface ProcessPayrollRunResult {
    run: PayrollRun;
    skipped: SkippedEmployee[];
}

export async function processPayrollRun(id: number): Promise<ProcessPayrollRunResult> {
    const { data } = await api.post<{ data: PayrollRun; skipped: SkippedEmployee[] }>(`/payroll/runs/${id}/process`);
    return { run: data.data, skipped: data.skipped };
}

export async function markPayrollRunPaid(id: number): Promise<PayrollRun> {
    const { data } = await api.post<{ data: PayrollRun }>(`/payroll/runs/${id}/mark-paid`);
    return data.data;
}

/** ONE page of payslips, narrowed and paged on the SERVER (ListPayslipsRequest). */
export async function listPayslips(filters: PayslipListFilters = {}): Promise<Paginated<Payslip>> {
    const { data } = await api.get<Paginated<Payslip>>('/payroll/payslips', { params: compactParams(filters) });
    return data;
}

export async function getPayslip(id: number): Promise<Payslip> {
    const { data } = await api.get<{ data: Payslip }>(`/payroll/payslips/${id}`);
    return data.data;
}
