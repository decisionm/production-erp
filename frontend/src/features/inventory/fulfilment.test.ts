import { describe, expect, it } from 'vitest';
import {
    FULFILMENT_STATE_LABEL,
    fulfilmentStateLabel,
    fulfilmentStateTone,
    holdSentence,
    plainDecimal,
    repointTargets,
    reservePrefill,
    sendToProductionPrefill,
} from './fulfilment';
import type { FulfilmentQueueRow, FulfilmentState } from './types';

/**
 * WHAT THE STORE'S SCREEN IS ALLOWED TO WORK OUT FOR ITSELF, and what it must
 * not.
 *
 * The prefills are the only arithmetic on that page, and each of them stands
 * where a wrong number is expensive: a Reserve box proposing more than is free
 * is a 422 the storekeeper has to decode, and one proposing 0 is a click that
 * holds nothing while looking like it held something. So the interesting cases
 * here are the ABSENCES — an unreadable figure, and a shortfall of nothing —
 * both of which must come back null and leave the box empty.
 *
 * The five states are pinned as a total map so a sixth state added on the
 * server cannot render as an empty cell, and `plainDecimal` is pinned against
 * the exponential notation `App\\Rules\\PlainDecimal` refuses.
 */

const ALL_STATES: FulfilmentState[] = [
    'untouched',
    'partially_allocated',
    'awaiting_production',
    'over_reserved',
    'fully_allocated',
];

function row(overrides: Partial<FulfilmentQueueRow> = {}): FulfilmentQueueRow {
    return {
        line_id: 1,
        sales_order_id: 10,
        customer: { id: 4, name: 'Aqua Foods' },
        item: { id: 7, sku: 'BTL-1L', name: '1 Litre Bottle' },
        ordered: '1000.0000',
        delivered: '0.0000',
        reserved: '0.0000',
        shortfall: '1000.0000',
        free: '250.0000',
        over_reserved: '0.0000',
        fulfilment_state: 'untouched',
        holds: [],
        request: null,
        can: { reserve: true, release: false, repoint: false, send_to_production: true },
        ...overrides,
    };
}

describe('the reserve prefill', () => {
    it('proposes the smaller of free stock and the line s remaining demand', () => {
        // Free is the binding cap: 250 on the shelf against 1000 still owed.
        expect(reservePrefill(row())).toBe(250);
        // Demand is the binding cap the other way round.
        expect(reservePrefill(row({ free: '900.0000', shortfall: '120.0000' }))).toBe(120);
    });

    it('proposes nothing rather than zero when there is nothing to hold', () => {
        // A 0 in the box is a click that holds nothing while looking like it
        // held something — the box opens empty instead.
        expect(reservePrefill(row({ free: '0.0000' }))).toBeNull();
        expect(reservePrefill(row({ shortfall: '0.0000' }))).toBeNull();
    });

    it('proposes nothing when either figure could not be read', () => {
        expect(reservePrefill(row({ free: '' }))).toBeNull();
        expect(reservePrefill(row({ shortfall: 'n/a' }))).toBeNull();
    });

    it('keeps four decimal places and does not round a fractional hold away', () => {
        expect(reservePrefill(row({ free: '0.2500', shortfall: '10.0000' }))).toBe(0.25);
    });
});

describe('the send-to-production prefill', () => {
    it('proposes the WHOLE shortfall, not the part free stock cannot cover', () => {
        // Reserving is the other button. A store that sent only shortfall −
        // free while never pressing Reserve would have asked the floor for
        // too little.
        expect(sendToProductionPrefill(row({ free: '250.0000', shortfall: '1000.0000' }))).toBe(1000);
    });

    it('proposes nothing when the line is not short', () => {
        expect(sendToProductionPrefill(row({ shortfall: '0.0000' }))).toBeNull();
        expect(sendToProductionPrefill(row({ shortfall: '' }))).toBeNull();
    });
});

describe('a quantity on its way to the server', () => {
    it('never leaves as exponential notation, which PlainDecimal refuses', () => {
        // JavaScript reaches for exponents on its own below 1e-6 and at 1e21,
        // and bcmath throws on both — this is the whole reason the helper
        // exists.
        expect(String(0.0000001)).toContain('e');
        expect(plainDecimal(0.0000001)).toBe('0.0000');
        // 1e21 is where `toFixed` gives up and hands the exponent back, so it
        // is pinned separately from the small case. The server still refuses
        // the figure (max:99999999999) — as too big, which is the truth, not
        // as malformed.
        expect(String(1e21)).toContain('e');
        expect(plainDecimal(1e21)).toBe('1000000000000000000000.0000');
    });

    it('accepts a decimal string unchanged in value, at the column s own precision', () => {
        expect(plainDecimal('12.5')).toBe('12.5000');
        expect(plainDecimal('+5')).toBe('5.0000');
    });

    it('refuses to invent a number for an absent one', () => {
        expect(plainDecimal(null)).toBeNull();
        expect(plainDecimal('')).toBeNull();
        expect(plainDecimal('abc')).toBeNull();
        expect(plainDecimal(Number.POSITIVE_INFINITY)).toBeNull();
    });
});

describe('the state vocabulary', () => {
    it('names every state the server can send', () => {
        expect(Object.keys(FULFILMENT_STATE_LABEL).sort()).toEqual([...ALL_STATES].sort());
        for (const state of ALL_STATES) {
            expect(fulfilmentStateLabel(state)).not.toBe('');
        }
    });

    it('paints only the promised-twice state red — the only one that owes a decision', () => {
        expect(fulfilmentStateTone('over_reserved')).toBe('red');
        const others = ALL_STATES.filter((state) => state !== 'over_reserved');
        expect(others.map(fulfilmentStateTone)).not.toContain('red');
    });

    it('passes an unknown state through unchanged rather than blanking it', () => {
        // A state this build has not been taught is still better evidence than
        // an empty cell, and it is exactly the row worth looking at.
        expect(fulfilmentStateLabel('some_future_state')).toBe('some_future_state');
        expect(fulfilmentStateTone('some_future_state')).toBe('default');
    });
});

describe('a hold', () => {
    it('reads "held for {customer} since {date}"', () => {
        expect(
            holdSentence({ customer: { id: 4, name: 'Aqua Foods' }, held_since: '2026-08-20T09:15:00+00:00' }),
        ).toBe('held for Aqua Foods since 2026-08-20');
    });

    it('dates the hold by the factory calendar, not by Greenwich', () => {
        // 20:30 UTC on the 25th is 02:00 IST on the 26th — the storekeeper
        // who took the hold that night calls it the 26th, and so must the
        // queue. Slicing the ISO instant said the 25th.
        expect(
            holdSentence({ customer: { id: 4, name: 'Aqua Foods' }, held_since: '2026-08-25T20:30:00+00:00' }),
        ).toBe('held for Aqua Foods since 2026-08-26');
    });

    it('still says what it is when the customer or the date is missing', () => {
        // A hold nobody can name is the one somebody most needs to see, so
        // the sentence degrades rather than disappearing.
        expect(holdSentence({ customer: null, held_since: '2026-08-20T09:15:00+00:00' })).toBe(
            'held for an unnamed customer since 2026-08-20',
        );
        expect(holdSentence({ customer: { id: 4, name: 'Aqua Foods' }, held_since: null })).toBe('held for Aqua Foods');
    });
});

describe('the re-point targets', () => {
    const source = row({ line_id: 1, item: { id: 7, sku: 'BTL-1L', name: '1 Litre Bottle' } });
    const sameItem = row({ line_id: 2, item: { id: 7, sku: 'BTL-1L', name: '1 Litre Bottle' } });
    const otherItem = row({ line_id: 3, item: { id: 9, sku: 'BTL-500', name: '500ml Bottle' } });

    it('offers only other lines wanting the SAME product', () => {
        // repoint() refuses a different product (repointItemMismatch) and the
        // hold's own line (cannotRepointToSameLine) — offering a choice the
        // server will reject is worse than offering none.
        expect(repointTargets([source, sameItem, otherItem], source).map((target) => target.line_id)).toEqual([2]);
    });

    it('offers nothing at all when the hold s own row has no product on it', () => {
        expect(repointTargets([sameItem], row({ item: null }))).toEqual([]);
    });
});
