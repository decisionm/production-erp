import { describe, expect, it } from 'vitest';
import { lineQcLine, receiptQcLine } from './grnQc';
import type { GoodsReceiptLineQc } from './types';

const inspection = (result: string, accepted = '90.0000', rejected = '10.0000'): GoodsReceiptLineQc => ({
    inspection: { id: 1, result, inspected_quantity: '100.0000', accepted_quantity: accepted, rejected_quantity: rejected, inspection_date: '2026-08-28' },
    bags: null,
});

describe('lineQcLine', () => {
    it('a pass is green and offers nothing further', () => {
        expect(lineQcLine(inspection('pass'))).toEqual({ color: 'green', text: 'QC passed', offerInspection: false });
    });

    it('a fail names the rejected quantity', () => {
        expect(lineQcLine(inspection('fail', '0.0000', '100.0000')).text).toBe('QC rejected — 100.0000 rejected');
    });

    it('a partial names both figures', () => {
        expect(lineQcLine(inspection('partial')).text).toBe('QC partial — 90.0000 accepted, 10.0000 rejected');
    });

    it('bags standing in waiting_qc are the hold, counted', () => {
        const line = lineQcLine({ inspection: null, bags: { waiting_qc: 2, rejected_qc: 0, total: 3 } });
        expect(line).toEqual({ color: 'orange', text: 'Waiting for QC — 2 of 3 bags held', offerInspection: true });
    });

    it('no inspection and no hold is a fact, not a demand — whether every arrival must pass QA is open', () => {
        const line = lineQcLine({ inspection: null, bags: null });
        expect(line).toEqual({ color: 'default', text: 'No inspection recorded', offerInspection: true });
    });

    it('an older backend that sends no qc key is admitted, not guessed at', () => {
        expect(lineQcLine(undefined).text).toBe('QC not readable here');
    });
});

describe('receiptQcLine', () => {
    it('a rejection outranks everything', () => {
        const line = receiptQcLine([{ qc: inspection('fail') }, { qc: { inspection: null, bags: { waiting_qc: 5, rejected_qc: 0, total: 5 } } }]);
        expect(line.text).toBe('QC rejection recorded');
    });

    it('a hold outranks nothing-yet', () => {
        const line = receiptQcLine([{ qc: { inspection: null, bags: { waiting_qc: 1, rejected_qc: 0, total: 2 } } }, { qc: { inspection: null, bags: null } }]);
        expect(line).toEqual({ color: 'orange', text: 'Waiting for QC', offerInspection: true });
    });

    it('QC done only when every line was inspected', () => {
        expect(receiptQcLine([{ qc: inspection('pass') }, { qc: inspection('pass') }]).text).toBe('QC done');
        expect(receiptQcLine([{ qc: inspection('pass') }, { qc: { inspection: null, bags: null } }]).text).toBe('No inspection recorded');
    });

    it('an older backend shows a dash rather than a claim', () => {
        expect(receiptQcLine([{ qc: undefined }]).text).toBe('—');
    });
});
