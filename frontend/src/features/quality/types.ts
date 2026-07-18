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
