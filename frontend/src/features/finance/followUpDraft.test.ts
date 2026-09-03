import { describe, expect, it } from 'vitest';
import { followUpDraft } from '@/features/finance/followUpDraft';
import type { ClientOutstanding } from '@/features/finance/types';

/**
 * WHAT THE FOLLOW-UP BUTTON PUTS IN FRONT OF A CUSTOMER.
 *
 * The composition only — no DOM, no antd, no mailto navigation. The button is
 * an <a href>, so the browser's own handling of the URL is not this repo's to
 * test; what IS this repo's is every figure and every claim inside it, because
 * this text leaves the building addressed to the people who owe the factory
 * money.
 *
 * Every figure here is synthetic (FC-06).
 */

const base: ClientOutstanding = {
    customer_id: null,
    customer_code: null,
    customer_name: null,
    customer_email: null,
    party_ledger_name: 'Northwind Traders',
    party_ledger_guid: 'ledger-guid-northwind',
    is_linked: false,
    balance_only: false,
    outstanding_amount: '50523510.6960',
    overdue_amount: '0.0000',
    pending_order_amount: '0.0000',
    pending_order_count: 0,
    pending_orders_without_value: 0,
    bill_count: 3,
    oldest_overdue_days: null,
    ageing: {
        current: '50523510.6960',
        d1_30: '0.0000',
        d31_60: '0.0000',
        d61_90: '0.0000',
        d90_plus: '0.0000',
        no_due_date: '0.0000',
    },
    bills: [],
    pending_orders: [],
};

function client(overrides: Partial<ClientOutstanding> = {}): ClientOutstanding {
    return { ...base, ...overrides };
}

describe('followUpDraft', () => {
    it('prints the amounts with the grouping the page prints, not a raw decimal', () => {
        // THE POINT OF SHARING ONE FORMATTER. `en-IN` groups by lakh and
        // crore, so this figure is "5,05,23,510.70" — not "50,523,510.70",
        // and certainly not the "50523510.696" that is on the wire. A client
        // reading a different-looking number in our mail than on our screen
        // has been handed an argument about the debt itself.
        const draft = followUpDraft(client(), '2026-09-03');

        expect(draft.body).toContain('Total outstanding: INR 5,05,23,510.70');
        expect(draft.body).not.toContain('50523510');
    });

    it('states the overdue amount and the oldest overdue days when money is overdue', () => {
        const draft = followUpDraft(
            client({ overdue_amount: '125000.0000', oldest_overdue_days: 129 }),
            '2026-09-03',
        );

        expect(draft.body).toContain('Overdue: INR 1,25,000.00');
        expect(draft.body).toContain('Oldest overdue: 129 days past due');
    });

    it('says nothing about overdue money when there is none', () => {
        // The base row is fully current. A letter that names an overdue line
        // reading zero invites a reply arguing a debt that is not claimed.
        const draft = followUpDraft(client(), '2026-09-03');

        expect(draft.body).not.toContain('Overdue');
        expect(draft.body).not.toContain('past due');
        // The balance itself is still chased — that is the whole letter.
        expect(draft.body).toContain('Total outstanding: INR');
    });

    it('gates the days clause separately from the amount', () => {
        // Tally stated no due date, so there is no age to quote — but the
        // money IS overdue. One gate for both would print "null days".
        const draft = followUpDraft(
            client({ overdue_amount: '125000.0000', oldest_overdue_days: null }),
            '2026-09-03',
        );

        expect(draft.body).toContain('Overdue: INR 1,25,000.00');
        expect(draft.body).not.toContain('Oldest overdue');
        expect(draft.body).not.toContain('null');
    });

    it('says nothing about overdue money for a purely balance-only client', () => {
        // Tally handed over a closing balance with no invoice behind it. The
        // service can only age a bill that carries a due date, so such a
        // client arrives with overdue_amount at zero — the letter is silent
        // on overdue money by arithmetic, not by a special case.
        const draft = followUpDraft(
            client({ balance_only: true, overdue_amount: '0.0000', oldest_overdue_days: null, bill_count: 0 }),
            '2026-09-03',
        );

        expect(draft.body).not.toContain('Overdue');
        expect(draft.body).toContain('Total outstanding: INR');
    });

    it('still states a bill-backed overdue figure on a partly balance-only client', () => {
        // `balance_only` is set for the whole client by ONE detail-less row,
        // so a client with real dated bills alongside it carries a genuine
        // overdue figure — and the Overdue column beside the button prints
        // it. Suppressing it here would tell the customer LESS than our own
        // screen does, which is the wrong direction to be wrong in.
        const draft = followUpDraft(
            client({ balance_only: true, overdue_amount: '125000.0000', oldest_overdue_days: 129 }),
            '2026-09-03',
        );

        expect(draft.body).toContain('Overdue: INR 1,25,000.00');
        expect(draft.body).toContain('Oldest overdue: 129 days past due');
    });

    it('carries the as-at date of the position', () => {
        const draft = followUpDraft(client(), '2026-09-03');

        expect(draft.body).toContain('as at 2026-09-03');
        expect(draft.subject).toContain('as at 2026-09-03');
    });

    it('drops the as-at clause rather than dating a letter "as at null"', () => {
        const draft = followUpDraft(client(), null);

        expect(draft.body).not.toContain('as at');
        expect(draft.body).not.toContain('null');
        expect(draft.subject).toBe('Northwind Traders — outstanding balance');
        // The balance is still the subject of the letter.
        expect(draft.body).toContain('Total outstanding: INR');
    });

    // ---- who it is addressed to ---------------------------------------------

    it('addresses the linked customer where there is an email on file', () => {
        const draft = followUpDraft(
            client({ customer_id: 7, customer_name: 'Northwind Traders Pvt Ltd', customer_email: 'accounts@northwind.example', is_linked: true }),
            '2026-09-03',
        );

        expect(draft.to).toBe('accounts@northwind.example');
        // Readable in the URL: the address is not percent-encoded into
        // accounts%40northwind.example, which handlers accept but nobody
        // scanning a link can check at a glance.
        expect(draft.url).toContain('mailto:accounts@northwind.example?subject=');
    });

    it('names the linked ERP customer rather than the Tally ledger', () => {
        // The same rule the Client column uses, from the same function.
        const draft = followUpDraft(
            client({ customer_id: 7, customer_name: 'Northwind Traders Pvt Ltd', is_linked: true }),
            '2026-09-03',
        );

        expect(draft.body).toContain('Northwind Traders Pvt Ltd');
        expect(draft.subject).toContain('Northwind Traders Pvt Ltd');
    });

    it('composes a recipient-less draft rather than nothing when no email is on file', () => {
        // THE STATE OF ALL 135 CLIENTS ON THIS INSTANCE. The draft is the
        // useful half; the sender supplies the address.
        const draft = followUpDraft(client(), '2026-09-03');

        expect(draft.to).toBe('');
        expect(draft.url.startsWith('mailto:?subject=')).toBe(true);
        expect(draft.body).toContain('Total outstanding: INR');
    });

    it('treats a blank email column as no recipient', () => {
        expect(followUpDraft(client({ customer_email: '   ' }), '2026-09-03').to).toBe('');
    });

    // ---- names out of Tally --------------------------------------------------

    it('encodes a client name that would otherwise truncate its own letter', () => {
        // Tally party names carry ampersands, question marks and apostrophes
        // as a matter of course. An unencoded `&` ends the subject parameter
        // and throws the rest of it away.
        const draft = followUpDraft(
            client({ party_ledger_name: "R & R Traders? O'Neill #2, Erode" }),
            '2026-09-03',
        );

        // The raw parts keep the real name...
        expect(draft.subject).toContain("R & R Traders? O'Neill #2, Erode");
        expect(draft.body).toContain("R & R Traders? O'Neill #2, Erode");

        // ...and the URL carries no separator that could split it. Exactly two
        // `&` and one `?` belong to the URL itself: the parameter joiner and
        // the query start.
        const query = draft.url.slice('mailto:?'.length);
        expect(draft.url.split('?').length - 1).toBe(1);
        expect(query.split('&').length - 1).toBe(1);
        expect(draft.url).toContain('%26');
        expect(draft.url).toContain('%3F');
        expect(draft.url).toContain('%23');
    });

    it('round-trips the encoded subject and body back to the raw parts', () => {
        // The parts are what the tests above assert; this pins that the URL
        // is those exact parts, so the two can never be asserted apart.
        const draft = followUpDraft(
            client({ party_ledger_name: 'Ganga & Co (Salem) — Unit #3', overdue_amount: '4500.5000', oldest_overdue_days: 61 }),
            '2026-09-03',
        );

        const query = new URLSearchParams(draft.url.slice(draft.url.indexOf('?') + 1));

        expect(query.get('subject')).toBe(draft.subject);
        expect(query.get('body')).toBe(draft.body);
    });

    it('keeps the letter short enough for a real mail handler', () => {
        // mailto: URLs are dropped or truncated past roughly 2000 characters
        // by real handlers, which is why the bills are summarised and never
        // itemised. A long Tally party name must not tip a draft over.
        const draft = followUpDraft(
            client({
                party_ledger_name: 'Sri Venkateswara Polymers and Packaging Industries (Coimbatore) Private Limited',
                overdue_amount: '125000.0000',
                oldest_overdue_days: 129,
            }),
            '2026-09-03',
        );

        expect(draft.url.length).toBeLessThan(2000);
    });
});
