import { describe, expect, it } from 'vitest';
import type { User } from '@/features/auth/types';
import {
    OUTSTANDING_IMPORT_ACCEPT,
    canImportClientOutstanding,
    outstandingImportOutcome,
} from '@/features/finance/outstandingImport';
import type { ClientOutstandingImportResult } from '@/features/finance/types';

/**
 * THE HAND PATH INTO THE CLIENT-OUTSTANDING POSITION, pinned:
 *
 *  - who the page draws the control for — the same single permission the
 *    endpoint is gated on, and never a substitute for it;
 *  - `skipped_empty` reading as its own outcome rather than borrowing either
 *    a success or a failure, which is the whole reason this module exists;
 *  - the copy never claiming anything reached Tally, because this traffic
 *    runs the other way.
 *
 * Every figure here is synthetic (FC-06).
 */

const user = (permissions: string[]): User => ({
    id: 1,
    name: 'A person',
    email: 'a@example.test',
    is_active: true,
    permissions,
});

const result = (over: Partial<ClientOutstandingImportResult> = {}): ClientOutstandingImportResult => ({
    bills: 37,
    orders: 2,
    parties: 4,
    as_of: '2026-09-30',
    skipped_empty: false,
    ...over,
});

describe('canImportClientOutstanding', () => {
    it('lets a finance.manage login import a position', () => {
        expect(canImportClientOutstanding(user(['finance.manage']))).toBe(true);
        expect(canImportClientOutstanding(user(['finance.view', 'finance.manage']))).toBe(true);
    });

    it('refuses a read-only finance login', () => {
        // The page is gated on `module:finance`, so plenty of people can READ
        // the debtor book. Replacing it is a write and is not the same right.
        expect(canImportClientOutstanding(user(['finance.view']))).toBe(false);
    });

    it('refuses everyone else', () => {
        // Not tally-sync's two-part test: whoever runs the sync queue is not
        // thereby allowed to overwrite the position, and Accounts does not
        // need the queue's permission to upload a file.
        expect(canImportClientOutstanding(user(['tally-sync.manage']))).toBe(false);
        expect(canImportClientOutstanding(user(['production.manage']))).toBe(false);
        expect(canImportClientOutstanding(user([]))).toBe(false);
    });

    it('refuses a login that is not there, or carries no permissions at all', () => {
        expect(canImportClientOutstanding(null)).toBe(false);
        expect(canImportClientOutstanding({ id: 1, name: 'x', email: 'x@y.z', is_active: true })).toBe(false);
    });
});

describe('OUTSTANDING_IMPORT_ACCEPT', () => {
    it('offers the Tally export and nothing else', () => {
        expect(OUTSTANDING_IMPORT_ACCEPT).toBe('.xml');
    });
});

describe('outstandingImportOutcome', () => {
    it('reports a read with the counts the server actually returned', () => {
        const outcome = outstandingImportOutcome(result());

        expect(outcome.tone).toBe('success');
        expect(outcome.text).toBe('37 bills, 2 orders, 4 parties — position as at 2026-09-30.');
    });

    it('prints a zero rather than dressing it up', () => {
        // Somebody who exported the wrong Tally report gets a position with
        // no orders in it. That reads as "0 orders", not as silence.
        expect(outstandingImportOutcome(result({ orders: 0 })).text).toContain('0 orders');
        expect(outstandingImportOutcome(result({ bills: 0, orders: 0, parties: 0 })).text)
            .toBe('0 bills, 0 orders, 0 parties — position as at 2026-09-30.');
    });

    it('counts one of each in the singular', () => {
        expect(outstandingImportOutcome(result({ bills: 1, orders: 1, parties: 1 })).text)
            .toBe('1 bill, 1 order, 1 party — position as at 2026-09-30.');
    });

    it('does NOT report a skipped import as a success', () => {
        // THE CASE THIS MODULE EXISTS FOR. The server answered 200 and kept
        // the position it already had; a green tick here would tell an
        // accountant the debtor book had just been refreshed when the figures
        // on screen are exactly as old as they were before the upload.
        const outcome = outstandingImportOutcome(result({ skipped_empty: true, bills: 0, orders: 0, parties: 0 }));

        expect(outcome.tone).toBe('warning');
        expect(outcome.text).toBe('Nothing usable in that file — the position as at 2026-09-30 is unchanged.');
    });

    it('says the position is unchanged, never that it was emptied', () => {
        // The opposite outcome, and the dangerous one to imply: the ERP did
        // not write an empty debtor book over a real one.
        const text = outstandingImportOutcome(result({ skipped_empty: true })).text;

        expect(text).toContain('unchanged');
        expect(text).not.toMatch(/cleared|emptied|removed|deleted/i);
    });

    it('never claims anything reached Tally, whichever way it went', () => {
        // Inbound traffic. A file was read INTO the ERP's mirror; nothing was
        // written to Tally, and the outward words belong to the sync queue.
        const texts = [
            outstandingImportOutcome(result()).text,
            outstandingImportOutcome(result({ skipped_empty: true })).text,
            outstandingImportOutcome(result({ as_of: '' })).text,
            outstandingImportOutcome(result({ as_of: '', skipped_empty: true })).text,
        ];

        for (const text of texts) {
            expect(text).not.toMatch(/\b(synced|sent|posted|uploaded to Tally|done|complete)\b/i);
        }
    });

    it('drops the date clause when there is no date to name', () => {
        // The premise of this control is a page with NOTHING pulled yet, so a
        // wrong export onto a dateless position is the likeliest first
        // encounter with a skip. "as at  is unchanged" must not reach anyone.
        expect(outstandingImportOutcome(result({ as_of: '', skipped_empty: true })).text)
            .toBe('Nothing usable in that file — the position is unchanged.');
        expect(outstandingImportOutcome(result({ as_of: '' })).text)
            .toBe('37 bills, 2 orders, 4 parties.');
        // Whitespace is not a date either.
        expect(outstandingImportOutcome(result({ as_of: '   ', skipped_empty: true })).text)
            .toBe('Nothing usable in that file — the position is unchanged.');
    });

    it('stays one sentence in both outcomes — no operator paragraph', () => {
        // A guard, not a style rule: the owner's standing instruction is that
        // floor and office users do not read blurbs, and an alert is exactly
        // where the next agent reaches for "let me just explain the skip".
        for (const outcome of [outstandingImportOutcome(result()), outstandingImportOutcome(result({ skipped_empty: true }))]) {
            expect(outcome.text.split('.').filter((part) => part.trim() !== '')).toHaveLength(1);
            expect(outcome.text.length).toBeLessThanOrEqual(120);
        }
    });
});
