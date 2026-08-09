import { describe, expect, it } from 'vitest';
import { labelDetails } from './cartonLabel';
import type { FinishedCarton } from './types';

/**
 * THE PRINTED LABEL'S EXACT LINES. Two decisions meet here and both are
 * asserted: DEC-20260810-001 ADDS the completion date (and nothing else —
 * no rate, cost or lot may ever reach a label), and the house rule that a
 * missing figure prints missing keeps the line out entirely when the server
 * could not resolve the completion instant.
 */
const carton = (overrides: Partial<FinishedCarton> = {}): FinishedCarton => ({
    id: 1,
    carton_no: '20260802-M01-001-C01',
    item: { id: 9, sku: 'BTL-500', name: '500ml Bottle' } as FinishedCarton['item'],
    pieces: '600.0000',
    is_partial: false,
    status: 'in_stock',
    delivery_id: null,
    net_weight_kg: '7.500',
    sales_order: null,
    batch: {
        shift_production_entry_id: 12,
        batch_number: '20260802-M01-001',
        production_date: '2026-08-02',
        machine: 'Machine 1',
        shift: 'Shift A',
        nos_per_box: '600',
    },
    created_at: null,
    ...overrides,
});

describe('labelDetails — the completion line (DEC-20260810-001)', () => {
    it('adds the completion date after the batch spine, and nothing else', () => {
        const withCompletion = labelDetails(
            carton({ completion: { completed_on: '2026-08-03', shift: 'Shift A' } }),
        );
        const without = labelDetails(carton());

        // Exactly one new line, in words a person on the floor reads.
        expect(withCompletion).toEqual([...without, 'Completed: 2026-08-03']);

        // The spine it joins is unchanged — the label's other lines are
        // frozen by the same decision.
        expect(without).toEqual([
            'BTL-500 — 500ml Bottle',
            '600 pcs',
            'Nos per box: 600',
            'Net weight: 7.5 kg',
            'Batch 20260802-M01-001 · 2026-08-02',
            'Machine 1 · Shift A shift',
        ]);
    });

    it('prints no completion line when the server resolved no instant', () => {
        const lines = labelDetails(
            carton({ completion: { completed_on: null, shift: 'Shift A' } }),
        );
        expect(lines.some((line) => line.startsWith('Completed'))).toBe(false);
    });

    it('never carries a rate, cost or lot on any line', () => {
        const lines = labelDetails(
            carton({ completion: { completed_on: '2026-08-03', shift: 'Shift A' } }),
        );
        for (const line of lines) {
            expect(line.toLowerCase()).not.toMatch(/rate|cost|lot|₹/);
        }
    });
});
