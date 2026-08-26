import { describe, expect, it } from 'vitest';
import { BAG_STATUSES, bagStatusLabel, bagStatusOptions, formatKg } from './bagStatus';

describe('the bag statuses the register offers', () => {
    /**
     * The backend enum has SIX cases. The four the frontend union names are the
     * pre-QC set; waiting_qc and rejected_qc arrived with the owner-confirmed
     * arrival flow, and a filter built from the narrow union would silently
     * offer no way to find either — including the rejected bags whose
     * kilograms have left usable stock.
     */
    it('offers all six the backend can return', () => {
        expect([...BAG_STATUSES]).toEqual([
            'waiting_qc',
            'in_store',
            'in_day_bin',
            'consumed',
            'returned',
            'rejected_qc',
        ]);
    });

    it('labels every one of them in the order it offers them', () => {
        expect(bagStatusOptions()).toEqual([
            { value: 'waiting_qc', label: 'Waiting QC' },
            { value: 'in_store', label: 'In store' },
            { value: 'in_day_bin', label: 'In day bin' },
            { value: 'consumed', label: 'Consumed' },
            { value: 'returned', label: 'Returned' },
            { value: 'rejected_qc', label: 'Rejected QC' },
        ]);
    });

    it('never renders a state as an empty tag', () => {
        expect(bagStatusLabel('quarantined')).toEqual({ text: 'quarantined', tone: 'default' });
        expect(bagStatusLabel('some_new_state').text).toBe('some new state');
    });

    it('dashes a bag that came back with no status at all', () => {
        expect(bagStatusLabel(null).text).toBe('—');
        expect(bagStatusLabel(undefined).text).toBe('—');
        expect(bagStatusLabel('').text).toBe('—');
    });
});

describe('a bag weight', () => {
    it('trims the trailing zeros the decimal column carries', () => {
        expect(formatKg('25.000000')).toBe('25');
        expect(formatKg('25.5000')).toBe('25.5');
    });

    it('keeps a real fraction', () => {
        expect(formatKg('0.2505')).toBe('0.2505');
    });

    it('dashes an absent weight rather than printing a zero', () => {
        expect(formatKg(null)).toBe('—');
        expect(formatKg(undefined)).toBe('—');
        expect(formatKg('')).toBe('—');
        expect(formatKg('not a number')).toBe('—');
    });
});
