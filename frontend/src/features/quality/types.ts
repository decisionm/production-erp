import type { Item } from '@/features/inventory/types';

export type InspectionResult = 'pass' | 'fail' | 'partial';

/**
 * ONE ARRIVAL LINE STILL WAITING FOR ITS INCOMING INSPECTION, exactly as
 * GET /api/v1/quality/incoming-inspections/pending serves it.
 *
 * This is NOT a `GoodsReceiptNoteLine` and must not be widened into one: the
 * server sends a hand-written whitelist so a quality login cannot be one
 * permission-grant away from a purchase rate or a supplier name (FC-06). If a
 * field is missing here, the answer is to ask whether quality is entitled to
 * it — not to reach for the procurement resource instead.
 */
export interface PendingInspectionLine {
    /** The goods_receipt_note_lines id — what an inspection is posted against. */
    id: number;
    /** "GRN-{id}", the app's own document number. Never the vendor's challan. */
    grn_reference: string | null;
    item: { id: number | null; sku: string | null; name: string | null };
    /** The exact decimal string the column holds — "123450.0000", never a float. */
    received_quantity: string;
    uom: string | null;
}

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
    /** What the disposition did to the arrival's bags, in words. */
    bag_disposition_note?: string | null;
    /** Reference a Rejections Out voucher will carry once its Tally shape is proven. */
    rejections_out_reference?: string | null;
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

export interface SpcCharacteristic {
    id: number;
    item: Item;
    name: string;
    unit_of_measure: string | null;
    target_value: string | null;
    lower_spec_limit: string | null;
    upper_spec_limit: string | null;
    is_active: boolean;
    created_at: string;
}

export interface SpcMeasurement {
    id: number;
    value: string;
    measured_at: string;
    recorded_by: string | null;
    notes: string | null;
    created_at: string;
}

export interface SpcChartPoint {
    id: number;
    measured_at: string;
    value: number;
    moving_range: number | null;
    beyond_limits: boolean;
    run_violation: boolean;
}

export interface SpcChart {
    characteristic_id: number;
    sufficient_data: boolean;
    points: SpcChartPoint[];
    center_line: number | null;
    ucl: number | null;
    lcl: number | null;
    mr_center_line: number | null;
    mr_ucl: number | null;
    mr_lcl: number | null;
}
