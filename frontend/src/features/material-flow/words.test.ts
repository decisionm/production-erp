import { describe, expect, it } from 'vitest';
import * as words from './words';
import {
    queueEmptyText,
    queueStatusFilter,
    ISSUE_IS_NOT_CONSUMPTION,
    ISSUE_STATUS_HELP,
    ISSUE_STATUS_LABEL,
    LOCATION_LABEL,
    OVER_ISSUE_IS_ORDINARY,
    REFUSAL_MESSAGE,
    REQUEST_STATUS_LABEL,
    STATE_COLUMN_LABEL,
    STATE_HELP,
    STATE_LABEL,
    STATE_LOCATION,
    TRACE_STOPS_AT_THE_ISSUE,
    TRANSITION_LABEL,
    describeRequestLine,
    formatQuantity,
    machineAppliesToRequest,
    machineFieldDecision,
    refusalMessage,
    stateLabel,
} from './words';

/** Every string this module exports, however deeply nested. */
function allStrings(value: unknown, out: string[] = []): string[] {
    if (typeof value === 'string') out.push(value);
    else if (Array.isArray(value)) value.forEach((v) => allStrings(v, out));
    else if (value && typeof value === 'object') Object.values(value).forEach((v) => allStrings(v, out));
    return out;
}

const EXPORTED_STRINGS = allStrings(
    Object.fromEntries(Object.entries(words).filter(([, v]) => typeof v !== 'function')),
);

describe('the three states are named, and never collapsed', () => {
    it('gives "issued to production" and "consumed" different words', () => {
        expect(STATE_LABEL.issued_to_production).not.toBe(STATE_LABEL.consumed);
        expect(STATE_COLUMN_LABEL.issued_to_production).not.toBe(STATE_COLUMN_LABEL.consumed);
    });

    it('says "not yet consumed" in the issued label itself, so no reader can mistake the two', () => {
        expect(STATE_LABEL.issued_to_production.toLowerCase()).toMatch(/not (yet )?consumed/);
        expect(STATE_COLUMN_LABEL.issued_to_production.toLowerCase()).toMatch(/not (yet )?consumed/);
    });

    it('spells out on every screen that an issue is not a consumption', () => {
        expect(ISSUE_IS_NOT_CONSUMPTION.toLowerCase()).toContain('not a consumption');
    });

    it('carries one plain sentence of help for each of the four states', () => {
        expect(Object.keys(STATE_HELP).sort()).toEqual(
            ['consumed', 'in_store', 'issued_to_production', 'returned_to_store'].sort(),
        );
        Object.values(STATE_HELP).forEach((help) => expect(help.length).toBeGreaterThan(20));
    });

    it('exposes the state labels through stateLabel()', () => {
        expect(stateLabel('consumed')).toBe(STATE_LABEL.consumed);
    });
});

describe('a state is not a place (DEC-20260817-001)', () => {
    it('names the three locations exactly as the owner named them', () => {
        expect(LOCATION_LABEL.raw_material_store).toBe('Raw Material Store');
        expect(LOCATION_LABEL.production_wip).toBe('Production/WIP');
        expect(LOCATION_LABEL.finished_goods_store).toBe('Finished Goods Store');
    });

    it('coins no synonym for the WIP location — no location is called "Issued to Production"', () => {
        Object.values(LOCATION_LABEL).forEach((label) => {
            expect(label.toLowerCase()).not.toContain('issued to production');
        });
    });

    it('mentions no Day Bin anywhere', () => {
        EXPORTED_STRINGS.forEach((s) => expect(s.toLowerCase()).not.toContain('day bin'));
    });

    it('puts issued-but-not-consumed material in Production/WIP, and consumption in no location at all', () => {
        expect(STATE_LOCATION.in_store).toBe('raw_material_store');
        expect(STATE_LOCATION.issued_to_production).toBe('production_wip');
        expect(STATE_LOCATION.returned_to_store).toBe('raw_material_store');
        expect(STATE_LOCATION.consumed).toBeNull();
    });
});

describe('quantities: unknown is never zero', () => {
    it('renders null, undefined and "" as an em dash', () => {
        expect(formatQuantity(null)).toBe('—');
        expect(formatQuantity(undefined)).toBe('—');
        expect(formatQuantity('')).toBe('—');
        expect(formatQuantity('not-a-number')).toBe('—');
    });

    it('renders a real zero as 0, because zero is a fact', () => {
        expect(formatQuantity('0.0000')).toBe('0');
    });

    it('trims trailing decimal zeros and appends the uom when given', () => {
        expect(formatQuantity('1250.5000')).toBe('1250.5');
        expect(formatQuantity('1250.5000', 'kg')).toBe('1250.5 kg');
        expect(formatQuantity(25, 'nos')).toBe('25 nos');
    });
});

describe('a request line reads as four named quantities', () => {
    const line = describeRequestLine(
        { requested: '100.0000', issued: '40.0000', returned: '5.0000', remaining: '60.0000' },
        'kg',
    );

    it('shows requested, issued-to-production, still-to-issue and returned, in that order', () => {
        expect(line.map((cell) => cell.key)).toEqual(['requested', 'issued', 'remaining', 'returned']);
        expect(line.map((cell) => cell.value)).toEqual(['100 kg', '40 kg', '60 kg', '5 kg']);
    });

    it('labels the issued cell as not-yet-consumed', () => {
        expect(line[1].label.toLowerCase()).toMatch(/not (yet )?consumed/);
    });

    it('never computes a remaining the server did not state', () => {
        const unknown = describeRequestLine(
            { requested: '100.0000', issued: null, returned: null, remaining: null },
            'kg',
        );
        expect(unknown.map((cell) => cell.value)).toEqual(['100 kg', '—', '—', '—']);
    });
});

describe('the machine/area field (FC-01, Q50)', () => {
    it('is shown for a consumable the server says takes one', () => {
        const decision = machineFieldDecision(true);
        expect(decision.show).toBe(true);
    });

    it('is refused for a common-input material, in the constitution\'s own words', () => {
        const decision = machineFieldDecision(false);
        expect(decision.show).toBe(false);
        expect(decision.note).toBe(REFUSAL_MESSAGE.machine_on_common_input);
        expect(decision.note.toLowerCase()).toContain('common');
    });

    it('names no machine, and guesses nothing, when the server has not said', () => {
        const decision = machineFieldDecision(null);
        expect(decision.show).toBe(false);
        expect(decision.note.toLowerCase()).toMatch(/not (been )?(told|said|known)|has not/);
    });

    it('cites FC-01 in the refusal, so a reader can find the rule', () => {
        expect(REFUSAL_MESSAGE.machine_on_common_input).toContain('FC-01');
    });
});

describe('one common-input material makes the whole request a common-input one', () => {
    it('applies a machine only when the server says so for every material on the request', () => {
        expect(machineAppliesToRequest([{ machine_applies: true }, { machine_applies: true }])).toBe(true);
    });

    it('refuses a machine as soon as one common-input material is on the request (FC-01)', () => {
        expect(machineAppliesToRequest([{ machine_applies: true }, { machine_applies: false }])).toBe(false);
    });

    it('stays unknown — never true — when the server has not said for some material', () => {
        expect(machineAppliesToRequest([{ machine_applies: true }, { machine_applies: null }])).toBeNull();
        expect(machineAppliesToRequest([])).toBeNull();
        expect(machineAppliesToRequest([undefined, undefined])).toBeNull();
    });

    it('feeds machineFieldDecision, so an unknown hides the field and shows the honest note', () => {
        const decision = machineFieldDecision(machineAppliesToRequest([{ machine_applies: null }]));
        expect(decision.show).toBe(false);
        expect(decision.note).toBe(REFUSAL_MESSAGE.machine_unknown_for_material);
    });
});

describe('refusals are plain English and complete', () => {
    it('gives every refusal code a message', () => {
        Object.entries(REFUSAL_MESSAGE).forEach(([code, message]) => {
            expect(message.length, code).toBeGreaterThan(15);
            expect(refusalMessage(code as keyof typeof REFUSAL_MESSAGE)).toBe(message);
        });
    });

    it('refuses a return of material that never went out', () => {
        expect(REFUSAL_MESSAGE.return_exceeds_issued.toLowerCase()).toContain('standing');
    });

    it('does NOT refuse an over-issue — a bag is not divisible, and the factory hands over whole bags', () => {
        expect(Object.keys(REFUSAL_MESSAGE)).not.toContain('issue_exceeds_remaining');
        expect(OVER_ISSUE_IS_ORDINARY.toLowerCase()).toContain('bag is not divisible');
    });

    it('says the trace stops at the issue — never "this batch used this bag"', () => {
        expect(TRACE_STOPS_AT_THE_ISSUE.toLowerCase()).toContain('issued to production');
        expect(TRACE_STOPS_AT_THE_ISSUE.toLowerCase()).toContain('never');
        expect(REFUSAL_MESSAGE.bag_to_batch_claim.toLowerCase()).toContain('calculated');
    });
});

describe('every status and transition has a word', () => {
    it('labels all five request statuses', () => {
        expect(Object.keys(REQUEST_STATUS_LABEL).sort()).toEqual(
            ['cancelled', 'draft', 'issued', 'partially_issued', 'submitted'].sort(),
        );
        expect(REQUEST_STATUS_LABEL.partially_issued.toLowerCase()).toContain('part');
    });

    it('labels the five store-issue statuses the backend can be in', () => {
        expect(Object.keys(ISSUE_STATUS_LABEL).sort()).toEqual(
            ['cancelled', 'completed', 'issued', 'partially_returned', 'returned'].sort(),
        );
    });

    it('says "not yet consumed" on the issued status, the one most likely to be misread', () => {
        expect(ISSUE_STATUS_LABEL.issued.toLowerCase()).toMatch(/not (yet )?consumed/);
    });

    it('keeps "returned" and "completed" apart — one gave material back, the other did not', () => {
        expect(ISSUE_STATUS_LABEL.returned).not.toBe(ISSUE_STATUS_LABEL.completed);
        expect(ISSUE_STATUS_HELP.completed.toLowerCase()).toContain('no stock');
    });

    it('labels every transition the screens offer', () => {
        expect(Object.keys(TRANSITION_LABEL).sort()).toEqual(
            ['cancel_issue', 'cancel_request', 'complete_issue', 'return_to_store', 'scan_bag', 'start_issue', 'submit_request'].sort(),
        );
    });
});

describe('FC-06: no rate, amount or vendor identity reaches these screens', () => {
    it('has no money or supplier word in any exported string', () => {
        const forbidden = /rate|amount|price|cost|value|vendor|supplier|invoice|₹/i;
        const offenders = EXPORTED_STRINGS.filter((s) => forbidden.test(s));
        expect(offenders).toEqual([]);
    });
});

describe('the store queue status filter', () => {
    it('defaults to the two OUTSTANDING statuses, so the queue is work not history', () => {
        expect(queueStatusFilter('open')).toEqual(['submitted', 'partially_issued']);
    });

    it('asks for EVERY request by omitting the status — this is how history is reached', () => {
        // The regression this pins: a fully issued request leaves the default
        // view, and before "All requests" existed there was no way back to it
        // from this screen. The store finished a handover and the row vanished.
        expect(queueStatusFilter('all')).toBeUndefined();
    });

    it('passes a single status straight through', () => {
        expect(queueStatusFilter('issued')).toBe('issued');
        expect(queueStatusFilter('cancelled')).toBe('cancelled');
    });

    it('tells an empty DEFAULT queue where the finished requests went', () => {
        const text = queueEmptyText(['submitted', 'partially_issued']);

        expect(text).toContain('still to issue');
        // The words that answer "where do we see the history".
        expect(text).toContain('Fully issued');
        expect(text).toContain('All requests');
    });

    it('says something different when the emptiness is the reader’s own filtering', () => {
        expect(queueEmptyText('cancelled')).toBe('No requests match these filters.');
        expect(queueEmptyText(undefined)).toBe('No requests match these filters.');
    });
});
