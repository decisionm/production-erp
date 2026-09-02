import type { ListParams } from '@/lib/listParams';

export type SalaryComponentKind = 'earning' | 'deduction';
export type SalaryCalculationType = 'fixed_amount' | 'percentage_of_basic';

export interface SalaryComponent {
    id: number;
    code: string;
    name: string;
    type: SalaryComponentKind;
    calculation_type: SalaryCalculationType;
    percentage: string | null;
    is_active: boolean;
    created_at: string;
}

export interface SalaryStructureLine {
    id: number;
    component: SalaryComponent;
    amount: string;
}

export interface SalaryStructure {
    id: number;
    employee?: { id: number; name: string };
    effective_from: string;
    lines: SalaryStructureLine[];
    created_at: string;
}

export type PayrollRunStatus = 'draft' | 'processed' | 'paid';
export type PayslipLineType = 'earning' | 'deduction' | 'employer_contribution';

export interface PayslipLine {
    id: number;
    label: string;
    type: PayslipLineType;
    amount: string;
}

export interface Payslip {
    id: number;
    payroll_run_id: number;
    employee?: { id: number; name: string };
    gross_earnings: string;
    total_deductions: string;
    net_pay: string;
    lines: PayslipLine[];
}

export interface PayrollRun {
    id: number;
    year: number;
    month: number;
    status: PayrollRunStatus;
    processed_at: string | null;
    paid_at: string | null;
    payslips?: Payslip[];
    created_at: string;
}

export interface SkippedEmployee {
    employee_id: number;
    employee_name: string;
}

/**
 * GET /payroll/runs — what ListPayrollRunsRequest accepts: `q` names a
 * PERIOD ("aug", "August 2026", "2026-08", "08/2026", "2026") or a status
 * word; `status` narrows to one; paging. Every field optional. This is both
 * the page's URL state and what the server is asked for — the two are the
 * same here, so one type serves.
 */
export interface PayrollRunListFilters extends ListParams {
    status?: PayrollRunStatus;
}

/**
 * GET /payroll/payslips — ListPayslipsRequest: the run (the filter this
 * page has always carried on its URL), the employee, `q` over the
 * employee's name or code, paging.
 */
export interface PayslipListFilters extends ListParams {
    payroll_run_id?: number;
    employee_id?: number;
}
