import { describe, expect, it } from 'vitest';
import { returnedTagText } from './returnedByQuality';

describe('returnedTagText', () => {
    it('is null when the batch was never returned', () => {
        expect(returnedTagText(null)).toBeNull();
        expect(returnedTagText(undefined)).toBeNull();
    });

    it('names no count on the first return', () => {
        expect(
            returnedTagText({
                returned_by_name: 'Priya',
                returned_at: '2026-09-01T10:00:00+00:00',
                reason: 'Recount the boxes on this pallet.',
                times: 1,
            }),
        ).toBe('Returned by Quality');
    });

    it('names the count from the second return on', () => {
        expect(
            returnedTagText({ returned_by_name: 'Priya', returned_at: null, reason: null, times: 2 }),
        ).toBe('Returned by Quality x2');
        expect(
            returnedTagText({ returned_by_name: null, returned_at: null, reason: null, times: 3 }),
        ).toBe('Returned by Quality x3');
    });

    it('falls back to the bare label rather than print a count of zero', () => {
        expect(
            returnedTagText({ returned_by_name: null, returned_at: null, reason: null, times: 0 }),
        ).toBe('Returned by Quality');
        expect(
            returnedTagText({
                returned_by_name: null,
                returned_at: null,
                reason: null,
                times: undefined as unknown as number,
            }),
        ).toBe('Returned by Quality');
    });
});
