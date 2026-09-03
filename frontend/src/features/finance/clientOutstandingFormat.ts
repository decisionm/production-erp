import type { ClientOutstanding } from './types';

/**
 * HOW THE OUTSTANDING POSITION NAMES A CLIENT AND SPELLS AN AMOUNT — in one
 * place, because both are now printed in two.
 *
 * These lived inside ClientOutstandingPage.tsx while the screen was the only
 * thing that showed them. The follow-up mail draft prints the same client's
 * name and the same two totals into a letter that goes OUT to that client, so
 * a second copy of either rule is a promise that the screen and the letter
 * will drift apart — a client reading "1,00,000.00" on our screen and
 * "100000.00" in our mail has been given two different-looking numbers for
 * one debt, and a ledger renamed in Tally would be chased under a name the
 * page no longer shows.
 *
 * Nothing here is page-specific and nothing here touches the DOM, so the mail
 * draft can be tested without rendering antd.
 */

/** Money arrives as a decimal STRING and is only ever formatted, never parsed to a number for display. */
export function money(value: string | null): string {
    if (value === null) return '—';

    const n = Number(value);
    if (!Number.isFinite(n)) return value;

    /*
     * The grouping is Indian (lakh/crore): `en-IN` renders 50523510.7 as
     * "5,05,23,510.70", not "50,523,510.70". That is the shape the factory's
     * accountant reads in Tally, and neither the table nor the mail may
     * quietly re-group it.
     */
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** The name the Client column prints first: the ERP customer where linked, else the Tally ledger. */
export function clientLabel(row: ClientOutstanding): string {
    return row.customer_id !== null ? (row.customer_name ?? row.party_ledger_name) : row.party_ledger_name;
}
