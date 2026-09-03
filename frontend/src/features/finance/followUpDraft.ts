import { clientLabel, money } from './clientOutstandingFormat';
import type { ClientOutstanding } from './types';

/**
 * THE FOLLOW-UP LETTER FOR ONE CLIENT'S OUTSTANDING BALANCE — composed here,
 * as a function of the row, so it can be pinned without a browser.
 *
 * IT NEVER SENDS ANYTHING. The result is a `mailto:` URL: pressing the button
 * opens the operator's own mail client on a draft that a human then reads,
 * edits and sends under their own name. There is no server call, no queue and
 * no outbound integration anywhere behind this — the standing rule in this
 * repo is that an agent prepares a draft and a person sends it, and a
 * collections letter to a real customer is the last place to bend that.
 *
 * WHY THE BUTTON IS LIVE ON EVERY ROW, INCLUDING THE ONES WITH NO ADDRESS.
 * Not one of the 135 clients on this instance is linked to an ERP customer,
 * so there is an email on file for nobody. Wiring the control to the address
 * would therefore have disabled it on every row in the table — a dead button
 * on 135 rows, which teaches an operator that the feature is broken. The
 * useful half of this is the composed letter, not the To line: with no
 * address the mailto simply carries no recipient and the sender picks it out
 * of their own contacts, with the figures already typed.
 *
 * WHAT IT CLAIMS IS EXACTLY WHAT THE ROW BESIDE THE BUTTON CLAIMS. The letter
 * states an overdue figure on the same test the Overdue column uses — is it
 * above zero — and on no other. A `balance_only` client is NOT special-cased
 * here, and the reason is arithmetic rather than taste: the service only adds
 * a bill into `overdue_amount` when that bill carries a due date it could
 * count days past, so a client whose position is nothing but detail-less
 * closing balances arrives with `overdue_amount` at zero and is silent on
 * overdue money already. A client flagged `balance_only` for ONE detail-less
 * row while carrying real dated bills has a genuine, bill-backed overdue
 * figure, the column prints it, and a letter that suppressed it would be
 * telling the customer less than our own screen does.
 *
 * The bills themselves are deliberately NOT itemised. A client with sixty
 * open bills would push the URL past the length real mail handlers accept,
 * and the letter would be silently truncated or dropped — the four facts
 * below are what a chaser needs to open a conversation.
 */

export interface FollowUpDraft {
    /** The linked customer's address, or '' — an empty To line the sender fills. */
    to: string;
    subject: string;
    body: string;
    /** `mailto:` — what an <a href> needs, with subject and body percent-encoded. */
    url: string;
}

/**
 * Compose the draft for one client, as at the date the position was read.
 *
 * `asOf` is the report's own `as_of` and is genuinely nullable: nothing may
 * have been pulled from Tally yet. A letter dated "as at null" is worse than
 * an undated one, so the as-at clause simply drops out.
 */
export function followUpDraft(row: ClientOutstanding, asOf: string | null): FollowUpDraft {
    const name = clientLabel(row);
    const asAt = (asOf ?? '').trim();

    /*
     * Passed through verbatim rather than percent-encoded. `encodeURIComponent`
     * would turn the `@` into `%40` — legal per RFC 6068, but needlessly picky
     * for the handlers that have to parse it, and an address out of the
     * customers table cannot contain the `&` or `?` that would actually break
     * the URL. An empty or whitespace-only column means no recipient.
     */
    const to = (row.customer_email ?? '').trim();

    const subject = asAt === ''
        ? `${name} — outstanding balance`
        : `${name} — outstanding as at ${asAt}`;

    const lines: string[] = [
        'Dear Sir/Madam,',
        '',
        asAt === ''
            ? `Our records show the following outstanding balance for ${name}:`
            : `Our records as at ${asAt} show the following outstanding balance for ${name}:`,
        '',
        `Total outstanding: INR ${money(row.outstanding_amount)}`,
    ];

    /*
     * ONLY WHERE THERE IS OVERDUE MONEY — the same test the Overdue column
     * applies, so the letter and the row can never disagree. An unreadable
     * amount is silence, never a sentence built on NaN.
     */
    const overdue = Number(row.overdue_amount);
    if (Number.isFinite(overdue) && overdue > 0) {
        lines.push(`Overdue: INR ${money(row.overdue_amount)}`);

        /*
         * Gated SEPARATELY from the amount. `oldest_overdue_days` is null
         * whenever Tally stated no due date, which can happen on a row that
         * still has a real overdue amount — one gate for both would print
         * "the oldest null days past due".
         */
        if (row.oldest_overdue_days !== null && row.oldest_overdue_days > 0) {
            lines.push(`Oldest overdue: ${row.oldest_overdue_days} days past due`);
        }
    }

    lines.push(
        '',
        'We request you to arrange payment of the amount due. If any of these figures do not agree with your records, please let us know and we will check them against our ledger.',
    );

    const body = lines.join('\n');

    return { to, subject, body, url: mailtoUrl(to, subject, body) };
}

/**
 * The URL an <a href> needs.
 *
 * SUBJECT AND BODY ARE ENCODED PART BY PART, never assembled and encoded once
 * — these strings come out of Tally, where a party is as likely to be called
 * "R & R Traders" or "Why Not? Plastics" as anything else. An unencoded `&`
 * ends the subject and starts a junk parameter; an unencoded `?` and `#` do
 * their own damage. Encoding each part after it is composed is what keeps a
 * punctuated client name from silently truncating its own letter.
 *
 * No recipient yields `mailto:?subject=…`, which is well-formed and opens a
 * draft with an empty To line — the intended state for every client on this
 * instance today.
 */
function mailtoUrl(to: string, subject: string, body: string): string {
    return `mailto:${to}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
