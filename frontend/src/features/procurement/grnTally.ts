/**
 * WHERE THIS GOODS RECEIPT STANDS WITH TALLY, in one line — the GRN half of
 * purchaseOrders.ts's tallyStateLine, and deliberately the same shape, so a
 * reader who has learned one cell has learned both. What differs is only
 * what a receipt genuinely lacks: no mirror case (a GRN is never pulled
 * FROM Tally), no draft/cancelled lifecycle, no dismissed state (nothing
 * withdraws a staged Receipt Note), and the disabled words name Q63 (does
 * the factory book Tally Receipt Notes at all?) rather than the PO's owner
 * gate.
 *
 * The order of precedence is the order of certainty:
 *   1. a queue entry (`tally` link) — its status is the live fact, worded
 *      exactly as the Tally Sync page words it;
 *   2. the staging record the listener wrote at arrival — enqueued without
 *      a readable link, refused with its reasons, or disabled (flag off);
 *   3. neither: the receipt was recorded before staging existed — said
 *      honestly rather than shown as a dash.
 *
 * Pure module: no React, no network — pinned by grnTally.test.ts.
 */
import type { TallyStateLine } from './purchaseOrders';
import { RECEIPT_NOTES_DISABLED_WORDS, tallyReasonWords } from './purchaseOrders';
import { statusColor as tallyStatusColor, statusLabel as tallyStatusLabel } from '@/features/tally-sync/drawer';
import type { GoodsReceiptNote } from './types';

const NOT_SENT = 'Not sent to Tally';

export function grnTallyStateLine(
    receipt: Pick<GoodsReceiptNote, 'tally' | 'tally_staging'>,
): TallyStateLine {
    const link = receipt.tally ?? null;
    if (link) {
        return {
            kind: 'link',
            text: tallyStatusLabel[link.status] ?? link.status,
            color: tallyStatusColor[link.status] ?? 'default',
            link,
            note: null,
        };
    }

    const staging = receipt.tally_staging ?? null;

    if (staging?.state === 'enqueued') {
        return {
            kind: 'enqueued',
            text: staging.entry_id
                ? `Queued for Tally — entry #${staging.entry_id} (status not readable here)`
                : 'Queued for Tally (status not readable here)',
            color: 'default',
            link: null,
            note: null,
        };
    }

    if (staging?.state === 'refused') {
        const reasons = (staging.reasons ?? []).map(tallyReasonWords).filter((words) => words !== '');

        return {
            kind: 'refused',
            text: reasons.length > 0 ? `${NOT_SENT} — ${reasons.join('; ')}` : `${NOT_SENT} — refused (no reason recorded)`,
            color: 'orange',
            link: null,
            note: null,
        };
    }

    if (staging?.state === 'disabled') {
        return {
            kind: 'disabled',
            text: `${NOT_SENT} — ${RECEIPT_NOTES_DISABLED_WORDS}`,
            color: 'default',
            link: null,
            note: null,
        };
    }

    return {
        kind: 'disabled',
        text: `${NOT_SENT} — recorded before Tally staging existed`,
        color: 'default',
        link: null,
        note: null,
    };
}
