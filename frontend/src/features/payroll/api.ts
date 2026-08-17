import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    PayrollRun,
    Payslip,
    SalaryCalculationType,
    SalaryComponent,
    SalaryComponentKind,
    SalaryStructure,
    SkippedEmployee,
} from './types';

export async function listSalaryComponents(): Promise<Paginated<SalaryComponent>> {
    const { data } = await api.get<Paginated<SalaryComponent>>('/payroll/salary-components');
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

export async function listSalaryStructures(employeeId?: number): Promise<Paginated<SalaryStructure>> {
    const { data } = await api.get<Paginated<SalaryStructure>>('/payroll/salary-structures', {
        params: employeeId ? { employee_id: employeeId } : undefined,
    });
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

export async function listPayrollRuns(): Promise<Paginated<PayrollRun>> {
    const { data } = await api.get<Paginated<PayrollRun>>('/payroll/runs');
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

export async function listPayslips(payrollRunId?: number): Promise<Paginated<Payslip>> {
    const { data } = await api.get<Paginated<Payslip>>('/payroll/payslips', {
        params: payrollRunId ? { payroll_run_id: payrollRunId } : undefined,
    });
    return data;
}

export async function getPayslip(id: number): Promise<Payslip> {
    const { data } = await api.get<{ data: Payslip }>(`/payroll/payslips/${id}`);
    return data.data;
}
