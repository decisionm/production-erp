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
