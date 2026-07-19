import type { Item } from '@/features/inventory/types';

export type InspectionResult = 'pass' | 'fail' | 'partial';

export interface IncomingInspection {
    id: number;
    goods_receipt_note_line_id: number;
    item: Item;
    inspected_quantity: string;
    accepted_quantity: string;
    rejected_quantity: string;
    result: InspectionResult;
    inspection_date: string;
    inspected_by: string | null;
    notes: string | null;
    created_at: string;
}

export type NonConformanceSeverity = 'minor' | 'major' | 'critical';
export type NonConformanceStatus = 'open' | 'closed';

export interface NonConformanceReport {
    id: number;
    incoming_inspection_id: number | null;
    item: Item;
    description: string;
    severity: NonConformanceSeverity;
    status: NonConformanceStatus;
    quantity_affected: string | null;
    raised_by: string | null;
    raised_date: string;
    resolution: string | null;
    closed_date: string | null;
    created_at: string;
}

export type CapaStatus = 'open' | 'in_progress' | 'closed';

export interface Capa {
    id: number;
    non_conformance_report_id: number | null;
    title: string;
    problem_statement: string;
    root_cause: string | null;
    corrective_action: string | null;
    preventive_action: string | null;
    owner?: { id: number; name: string };
    due_date: string | null;
    status: CapaStatus;
    verified_effective: boolean | null;
    closed_date: string | null;
    created_by: string | null;
    created_at: string;
}

export type MeasuringInstrumentStatus = 'active' | 'retired';
export type CalibrationResult = 'pass' | 'fail' | 'adjusted';

export interface CalibrationRecord {
    id: number;
    calibrated_date: string;
    certificate_number: string | null;
    result: CalibrationResult;
    performed_by: string | null;
    notes: string | null;
    created_at: string;
}

export interface MeasuringInstrument {
    id: number;
    code: string;
    name: string;
    location: string | null;
    calibration_frequency_days: number;
    last_calibrated_date: string | null;
    next_calibration_due: string;
    status: MeasuringInstrumentStatus;
    calibration_records: CalibrationRecord[];
    created_at: string;
}
