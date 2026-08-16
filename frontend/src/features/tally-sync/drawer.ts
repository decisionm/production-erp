import type { TallySyncEntry, TallySyncStatus, TimelineItem } from './types';

/**
 * Pure helpers behind the Tally Sync page and its detail drawer. No React,
 * no axios — everything here is a function of its arguments so it can be
 * tested without a DOM (drawer.test.ts). The words here are the words the
 * table and the drawer both use: one place, so the two cannot drift.
 */

export const statusColor: Record<TallySyncStatus, string> = {
    pending: 'default',
    synced: 'green',
    failed: 'red',
    // Neutral on purpose: a dismissed voucher is resolved history, not a
    // problem — red would drag eyes back to a row nobody needs to act on.
    dismissed: 'default',
};

export const statusLabel: Record<TallySyncStatus, string> = {
    pending: 'Waiting for agent',
    synced: 'In Tally',
    failed: 'FAILED',
    dismissed: 'Dismissed — never sent',
};

/** One stock line of a production voucher, as the payload carries it. */
export type VoucherStockLine = { item: string; quantity: string; godown?: string | null };

/**
 * The produced[]/consumed[] arrays out of a production voucher's payload —
 * batch and consolidated shift vouchers both carry them — or null for the
 * voucher types that don't (sales, receipt/delivery notes, journals), which
 * render their lines[] instead.
 */
export function voucherStockLines(entry: TallySyncEntry, key: 'produced' | 'consumed'): VoucherStockLine[] | null {
    const value = entry.payload?.[key];
    if (!Array.isArray(value)) {
        return null;
    }

    const lines = value.filter(
        (line): line is VoucherStockLine =>
            typeof line === 'object' && line !== null
            && typeof (line as VoucherStockLine).item === 'string'
            && typeof (line as VoucherStockLine).quantity === 'string',
    );

    return lines.length === value.length ? lines : null;
}

/** A string field out of the voucher payload, or null if it isn't usable. */
export function payloadText(entry: TallySyncEntry, key: string): string | null {
    const value = entry.payload?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * The number staff will search for in Tally. Mirrors the backend's
 * TallySyncEntry::voucherNumber() fallback exactly — same answer on both
 * sides of the wire, so what the screen says matches what the log says.
 */
export function voucherNumber(entry: TallySyncEntry): string {
    return payloadText(entry, 'voucher_number') ?? `#${entry.id}`;
}

/**
 * Server-stamped instants (synced_at, delivered_at, created_at) converted to
 * the viewer's clock. Deliberately NOT lib/datetime's formatDateTime: that one
 * reads the ISO string as written, which is right for a wall-clock time the
 * factory typed in, and wrong here — these are stamped `now()` in UTC, and
 * slicing them would show an IST user a sync that happened at 14:30 as 09:00.
 */
export function instant(value: string | null | undefined): string {
    if (!value) return '—';
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('en-IN', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * The hold copy — one tag and one line under it — worded once for the
 * Status column and the drawer's Release section. A held shift voucher is
 * deliberately not with the agent yet (DEC-20260807-011): a different claim
 * from "waiting for agent", which reads as "the factory machine should have
 * taken this already".
 */
export function holdCopy(hold: NonNullable<TallySyncEntry['hold']>): { tag: string; detail: string } {
    return hold.phase === 'collecting'
        ? { tag: 'Collecting the shift', detail: `Collecting until ${instant(hold.shift_ends_at)}` }
        : { tag: 'Quiet period', detail: `Waiting: quiet period — last entry joined ${instant(hold.last_merged_at)}` };
}

// ---- mappings ---------------------------------------------------------------

/** A mapping-state badge: the Tag colour, the words on it, and the backend's note as its tooltip. */
export interface MappingBadge {
    color: string;
    text: string;
    title: string | null;
}

/**
 * How many rows share an ambiguous name, and what to call them. The COUNT
 * is the server's structured `shared_count` (LineMappingResolver counted
 * the rows); the regex over the note's head — "3 items in this ERP share
 * the name …", "2 warehouses in this ERP share …" — is kept only as the
 * FALLBACK for a row that carries no `shared_count`, and to read the noun.
 * Nothing here counts anything.
 */
function sharedNameCount(note: string | null | undefined, sharedCount?: number | null): { count: number; noun: string } | null {
    const match = /^(\d+)\s+([a-z]+)\b/i.exec(note ?? '');

    if (typeof sharedCount === 'number') {
        return { count: sharedCount, noun: match?.[2] ?? 'rows' };
    }

    return match ? { count: Number(match[1]), noun: match[2] } : null;
}

/**
 * How ONE name resolved, as a Tag: one colour and one wording per state
 * (LineMappingResolver's six, plus the surface's `withheld`), the backend's
 * note riding as the tooltip. The colours grade the CLAIM the ERP can make,
 * not a verdict on Tally: green is a GUID (the strongest claim without
 * reading Tally — and its note still says "posts if that master still
 * carries this name; the ERP cannot know"), gold is "posts if a master so
 * named exists there — this ERP cannot know", red is "will not post"
 * (nothing by that name; or a local fixture, which never posts whatever it
 * carries), orange is "more than one row shares this name — Tally would
 * match one; the ERP cannot say which", grey is "nothing to map" or
 * "withheld (FC-06)" — the vendor row of a Receipt Note for a reader who
 * may not see who supplied it. An unknown state is passed through as grey
 * text, never thrown on.
 */
export function mappingBadge(state: string, note?: string | null, sharedCount?: number | null): MappingBadge {
    const title = note ?? null;

    switch (state) {
        case 'identity':
            return { color: 'green', text: 'identity', title };
        case 'name_only':
            return { color: 'gold', text: 'name only', title };
        case 'unmapped':
            return { color: 'red', text: 'unmapped', title };
        case 'fixture':
            return { color: 'red', text: 'local fixture — never posts', title };
        case 'ambiguous': {
            // The count is the backend's own (it counted the rows sharing
            // the name); repeated here, never rounded to "mapped" or "missing".
            const shared = sharedNameCount(note, sharedCount);
            const head = shared ? `${shared.count} ${shared.noun} share this name` : 'ambiguous';

            return { color: 'orange', text: `${head} — Tally would match one; the ERP cannot say which`, title };
        }
        case 'none':
            return { color: 'default', text: 'none', title };
        case 'withheld':
            // FC-06's second half: the row is kept and says why it is empty —
            // a blank would read as "no party", which is a different claim.
            return { color: 'default', text: 'withheld (FC-06)', title };
        default:
            return { color: 'default', text: state, title };
    }
}

// ---- payload ---------------------------------------------------------------

/** One column of the drawer's payload-lines table. */
export interface PayloadColumn {
    key: string;
    title: string;
    align?: 'right';
}

const RATE_KEYS = ['rate', 'amount'] as const;

/**
 * The columns for a lines[] payload (Sales, Receipt Note, Delivery Note,
 * Journal), decided by the KEYS THE SERVER SENT on the sample line, not by
 * what the type could carry.
 *
 * Rate / Amount appear only when the keys are present: TallySyncEntryResource
 * OMITS them (never nulls them) for a reader without finance — FC-06 — so
 * their presence is the server's own ruling arriving with the data. A
 * column advertising a number it will not show only sends somebody looking
 * for it (the MaterialLotsPage precedent). A Delivery Note never carried a
 * price at all, so it never grows one either.
 *
 * A Journal's lines name a ledger and the two sides; an unclassified row is
 * described by whatever shape it actually has.
 */
export function payloadColumns(categoryKey: string, sampleLine: Record<string, unknown> | null | undefined): PayloadColumn[] {
    const line = sampleLine && typeof sampleLine === 'object' ? sampleLine : {};
    const has = (key: string) => Object.prototype.hasOwnProperty.call(line, key);

    const isLedgerShaped = categoryKey === 'journal' || (has('ledger') && !has('item'));

    if (isLedgerShaped) {
        return [
            { key: 'ledger', title: 'Ledger' },
            ...(has('debit') ? [{ key: 'debit', title: 'Debit', align: 'right' as const }] : []),
            ...(has('credit') ? [{ key: 'credit', title: 'Credit', align: 'right' as const }] : []),
            ...(has('memo') ? [{ key: 'memo', title: 'Memo' }] : []),
        ];
    }

    return [
        { key: 'item', title: 'Item' },
        { key: 'quantity', title: 'Quantity', align: 'right' },
        ...RATE_KEYS.filter(has).map((key) => ({
            key,
            title: key === 'rate' ? 'Rate' : 'Amount',
            align: 'right' as const,
        })),
    ];
}

// ---- timeline ---------------------------------------------------------------

/**
 * The event vocabulary (TallySyncEventKind) as a person reads it. An event
 * this table does not know is shown by its raw name rather than guessed at.
 */
const EVENT_LABELS: Record<string, string> = {
    'voucher.enqueued': 'Queued',
    'voucher.merged': 'Entries merged',
    'voucher.rebuilt': 'Payload rebuilt',
    'pending.delivered': 'Handed to the agent',
    'voucher.synced': 'Accepted by Tally',
    'voucher.failed': 'Rejected by Tally',
    'voucher.failure_refused': 'Failure report refused',
    'voucher.retried': 'Resynced',
    'voucher.dismissed': 'Dismissed',
    'voucher.released': 'Released',
};

export function eventLabel(event: string): string {
    return EVENT_LABELS[event] ?? event;
}

/** One row of the drawer's Timeline, ready for antd's `items` minus the React nodes. */
export interface TimelineRow {
    key: string;
    label: string;
    at: string | null;
    actor: string | null;
    detail: string | null;
    source: TimelineItem['source'];
    /** A reconstruction (backfilled event or a bare column) — rendered muted and tagged. */
    muted: boolean;
    tag: 'reconstructed' | null;
    color: 'green' | 'red' | 'blue' | 'gray';
}

function eventColor(event: string): TimelineRow['color'] {
    switch (event) {
        case 'voucher.synced':
            return 'green';
        case 'voucher.failed':
        case 'voucher.failure_refused':
            return 'red';
        default:
            return 'blue';
    }
}

/**
 * The server's timeline (EntryPresenter::timeline — events merged with the
 * entry's own timestamps, oldest first) as Timeline rows. Order, actor and
 * detail are the server's, untouched. A reconstructed row — a backfilled
 * event, or a column read now with no event behind it — is muted grey
 * whatever it says and tagged "reconstructed": a reconstructed "synced" is
 * not a green tick, because nobody observed it.
 */
export function timelineItems(rows: readonly TimelineItem[] | null | undefined): TimelineRow[] {
    return (rows ?? []).map((row, index) => ({
        key: `${row.at ?? 'no-time'}·${row.event}·${index}`,
        label: eventLabel(row.event),
        at: row.at,
        actor: row.actor_label,
        detail: row.detail,
        source: row.source,
        muted: row.backfilled,
        tag: row.backfilled ? 'reconstructed' : null,
        color: row.backfilled ? 'gray' : eventColor(row.event),
    }));
}

// ---- ERP source -------------------------------------------------------------

/**
 * Where the ERP side of this voucher lives — the same "Open production
 * entries" link the table's Batch / Source cell has always shown, offered
 * only for a production voucher (by module, or by a Shift-shaped syncable
 * the classifier could not place). A Sales or Receipt Note voucher gets no
 * link rather than a wrong one.
 */
export function sourceLink(entry: TallySyncEntry): { to: string; label: string } | null {
    const production = entry.category?.source_module === 'production'
        || entry.syncable_type === 'Shift'
        || entry.syncable_type === 'ShiftProductionEntry';

    return production ? { to: '/production/shift-production', label: 'Open production entries' } : null;
}

/** The short name of each counted mapping state, for the summary row's tags. */
export const mappingStateShort: Record<string, string> = {
    identity: 'identity',
    name_only: 'name only',
    unmapped: 'unmapped',
    fixture: 'fixture',
    ambiguous: 'ambiguous',
};

/**
 * Whether the "Fixed after N failed attempts" line may show. Judged on STATUS,
 * never on the absence of error text: since Phase 3 the server nulls
 * error_message for a reader who may not read Tally's rejection text on a
 * supplier-party voucher (FC-06 — error_withheld says why), so a still-FAILED
 * row can carry a null error. Reading that null as "fixed" would tell the one
 * reader who cannot see the error that there is nothing left to do.
 */
export function showsFixedAfterFailures(entry: {
    status: string;
    error_message: string | null;
    resolution_log?: unknown[] | null;
}): boolean {
    return entry.status !== 'failed' && (entry.resolution_log?.length ?? 0) > 0 && !entry.error_message;
}
