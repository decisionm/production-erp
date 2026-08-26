import type { TallySnapshotAnswer, TallySyncEntry, TallySyncSnapshot, TallySyncStatus, TimelineItem } from './types';

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

/** Month names for voucherDate(); index 0 is January. */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * THE VOUCHER'S OWN DATE, rendered literally — the date this document
 * carries in Tally's books (payload.voucher_date, surfaced as
 * `business_date`).
 *
 * NO Date, NO dayjs, ON PURPOSE. A voucher date is a calendar date the
 * factory decided, not an instant: it is stored as "2026-07-23" and it is
 * the 23rd in every timezone on earth. `new Date('2026-07-23')` parses that
 * as UTC MIDNIGHT, so a viewer west of Greenwich renders the 22nd — an
 * accountant reconciling a day would be looking at the wrong day, and the
 * screen would disagree with the voucher in Tally. Splitting the string
 * cannot do that.
 *
 * Anything that is not a plain YYYY-MM-DD is shown as it came rather than
 * reinterpreted, and a missing date is an em dash — never today's, never a
 * guess.
 */
export function voucherDate(value: string | null | undefined): string {
    if (!value) return '—';

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
    if (!match) return value;

    const [, year, month, day] = match;
    const name = MONTHS[Number(month) - 1];

    return name ? `${day} ${name} ${year}` : value;
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
    'snapshot.stored': 'Snapshot uploaded',
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
 * One row of the source matrix below: what the link says, and where it
 * goes. `to` is a plain path for a destination that needs no id (the
 * production entries list), or a function of the entry's `syncable_id` for
 * a page that opens ONE document.
 */
interface SourceTarget {
    label: string;
    to: string | ((id: number) => string);
}

/**
 * WHERE THE ERP SIDE OF A VOUCHER LIVES — the whole table, once, keyed on
 * the CATEGORY KEY the server sends (TallyTransactionCategory's value) and
 * on nothing else.
 *
 * The table is exact: a key that is not written here gets NO link. That is
 * the point of it. The queue holds seven ERP-built categories and the
 * catalogue names more that live in the accountant's books, and a resolver
 * that reasoned about a row instead of looking it up is how `receipt`
 * (the accountant's Receipt voucher, which the ERP does not mirror) ends
 * up pointed at the goods-receipts page. So: `journal`, every Tally-only
 * key, `sales_order` and `unknown` are absent on purpose, not forgotten.
 *
 * THE MORPH IS NOT CONSULTED. It used to be — a Shift-shaped syncable was
 * read as production whatever its category said — and that is exactly the
 * guess this table exists to stop: the classifier returns Unknown for a
 * label/morph mismatch (TransactionClassifier), and an Unknown row must
 * offer no destination rather than a plausible one.
 *
 * The query spellings are the destination pages' own, not invented here:
 *   `?grn=`      GoodsReceiptsPage reads it (`searchParams.get('grn')`)
 *   `?open=`     usePurchaseOrderListParams reads it (`?po=` is its legacy alias)
 *   `?open=INV-` / `?open=DN-`  useSalesListParams via parseDocumentRef —
 *                the same string sales/filters.ts documentPath() builds, and
 *                EntrySource.test.tsx pins the two against each other.
 */
const SOURCE_BY_CATEGORY = new Map<string, SourceTarget>([
    // A Map, not an object literal: the key arrives off the wire, and a
    // plain object answers `constructor` and `toString` out of its
    // prototype — a lookup that must mean "no such category" returning a
    // function is how a resolver throws on data instead of refusing it.
    //
    // Both production categories — per shift and per batch — land on the
    // one list the floor opens; it carries no id, so it is offered
    // whatever the syncable id is.
    ['production_stock_journal_shift', { label: 'Open production entries', to: '/production/shift-production' }],
    ['production_stock_journal_batch', { label: 'Open production entries', to: '/production/shift-production' }],
    ['receipt_note', { label: 'Open the goods receipt', to: (id) => `/procurement/goods-receipts?grn=${id}` }],
    ['purchase_order', { label: 'Open the purchase order', to: (id) => `/procurement/purchase-orders?open=${id}` }],
    ['sales_invoice', { label: 'Open the invoice', to: (id) => `/sales/invoices?open=INV-${id}` }],
    ['delivery_note', { label: 'Open the delivery note', to: (id) => `/sales/deliveries?open=DN-${id}` }],
]);

/**
 * The ERP record behind ONE voucher, as a link — the same answer for the
 * queue's Source cell and the drawer's Source record, because both ask
 * this and nothing else.
 *
 * Null is a real answer and the common one: a category with no row in the
 * matrix (a Journal, an accountant's own voucher type, an unclassified
 * row), or an id a document page could not be opened with. A page that
 * opens one document is only linked with a POSITIVE WHOLE id — `?grn=0`
 * or `?open=INV--3` would send a person to a page that finds nothing and
 * reads as "the document is gone". The production list takes no id, so an
 * unusable id cannot cost it its link.
 */
export function sourceLink(entry: TallySyncEntry): { to: string; label: string } | null {
    const target = SOURCE_BY_CATEGORY.get(entry.category?.key ?? '');

    if (!target) return null;
    if (typeof target.to === 'string') return { to: target.to, label: target.label };

    const id = entry.syncable_id;

    return Number.isInteger(id) && id > 0 ? { to: target.to(id), label: target.label } : null;
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

// ---- snapshots (Phase 4: what the agent sent / what Tally answered) --------

const XML_INDENT = '  ';

/**
 * One token of an XML string: a declaration / processing instruction, a
 * comment, a CDATA section, a tag (open, close or self-closing), or the
 * text between tags. Order matters — the special forms are tried before
 * the generic tag so a `>` inside a comment or CDATA cannot cut it short;
 * the tag itself is quote-aware, so a `>` inside a quoted attribute value
 * cannot either.
 */
const XML_TOKEN = /<!\[CDATA\[[\s\S]*?\]\]>|<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<(?:[^>"']|"[^"]*"|'[^']*')*>|[^<]+/g;

function xmlTagName(tag: string): string {
    const match = /^<\/?\s*([^\s/>]+)/.exec(tag);

    return match?.[1] ?? '';
}

/**
 * The XML the agent sent, pretty-printed for a person: one tag per line,
 * indented two spaces per depth, a text-only element (`<NAME>value</NAME>`
 * — most of a Tally voucher) and an empty element kept on one line. No
 * library, no DOM: a small tokenizer, so it runs in a test and never
 * throws — an unbalanced close tag clamps the depth at zero, mixed
 * content stands on its own line, and whitespace between tags is
 * dropped, so already-pretty input comes back unchanged.
 *
 * FOR EYES ONLY. Text nodes are trimmed and inter-tag whitespace removed,
 * so the output is not byte-identical to what was posted; the Copy button
 * copies the RAW string, which is what the sha256 was computed over.
 */
export function formatXml(xml: string): string {
    const tokens = xml.match(XML_TOKEN) ?? [];
    const lines: string[] = [];
    let depth = 0;
    const indent = () => XML_INDENT.repeat(depth);
    const isText = (token: string | undefined): token is string => token !== undefined && !token.startsWith('<');
    const isCloseOf = (token: string | undefined, name: string): token is string =>
        token !== undefined && token.startsWith('</') && xmlTagName(token) === name;

    for (let i = 0; i < tokens.length; i += 1) {
        const token: string = tokens[i];

        if (!token.startsWith('<')) {
            const text = token.trim();
            if (text !== '') lines.push(indent() + text);
            continue;
        }

        if (token.startsWith('<?') || token.startsWith('<!')) {
            // Declaration, comment or CDATA — a line of its own at the current depth.
            lines.push(indent() + token.trim());
            continue;
        }

        if (token.startsWith('</')) {
            depth = Math.max(0, depth - 1);
            lines.push(indent() + token);
            continue;
        }

        if (/\/\s*>$/.test(token)) {
            lines.push(indent() + token);
            continue;
        }

        // An open tag. A leaf — text then the matching close — and an
        // empty element both stay on one line; anything else opens a level.
        const name = xmlTagName(token);
        const next = tokens[i + 1];
        if (isText(next) && isCloseOf(tokens[i + 2], name)) {
            lines.push(indent() + token + next.trim() + tokens[i + 2]);
            i += 2;
            continue;
        }
        if (isCloseOf(next, name)) {
            lines.push(indent() + token + next);
            i += 1;
            continue;
        }

        lines.push(indent() + token);
        depth += 1;
    }

    return lines.join('\n');
}

/**
 * The one-line header of a snapshot panel — "attempt N · agent vX · when ·
 * sha256 (first 12) · N bytes · payload verdict" — the facts EVERY
 * tally-sync.view reader gets whatever the XML gate says. What the
 * snapshot cannot say reads as unknown ("attempt —", "agent version
 * unknown", "payload not compared"), never as a guess. `payload changed
 * since` means the cloud regenerated the payload (a Resync) after this
 * XML was built from it.
 */
export function snapshotHeadline(snapshot: TallySyncSnapshot): string {
    const version = snapshot.agent_version?.trim() || null;
    const bytes = snapshot.xml_bytes === null
        ? 'size unknown'
        : snapshot.xml_bytes === 1
            ? '1 byte'
            : `${snapshot.xml_bytes} bytes`;
    // A STORE-TIME verdict — judged once, when the cloud took the snapshot,
    // against the payload it held then; a later Resync does not re-judge it.
    const payload = snapshot.payload_matches === true
        ? 'payload matched at upload'
        : snapshot.payload_matches === false
            ? 'payload had changed before upload'
            : 'payload not compared';

    return [
        `attempt ${snapshot.attempt ?? '—'}`,
        version ? `agent ${version.startsWith('v') ? version : `v${version}`}` : 'agent version unknown',
        instant(snapshot.created_at),
        `sha256 ${snapshot.xml_sha256.slice(0, 12)}`,
        bytes,
        payload,
    ].join(' · ');
}

/** What the "What the agent sent" block shows: the XML itself, the server's withheld note, or the fact that no body was uploaded. */
export interface SnapshotXmlDecision {
    kind: 'xml' | 'withheld' | 'none';
    text: string;
}

/**
 * Whether the drawer may show this snapshot's XML — decided by WHAT THE
 * SERVER SENT, never re-judged here. `xml` present → show it (raw; the
 * component formats it). `xml` null with `xml_withheld` → the reader may
 * not see it (FC-06: the XML carries rates or a party and this reader has
 * no finance standing), and the server's note is shown in place — never
 * a blank, which would read as "nothing was sent". Neither → the agent
 * uploaded no body (it omits the XML above 2 MB) and only the sha256 and
 * size stand.
 */
export function snapshotXmlDecision(snapshot: TallySyncSnapshot): SnapshotXmlDecision {
    if (typeof snapshot.xml === 'string' && snapshot.xml !== '') {
        return { kind: 'xml', text: snapshot.xml };
    }
    if (snapshot.xml_withheld) {
        return { kind: 'withheld', text: snapshot.xml_withheld };
    }

    return {
        kind: 'none',
        text: 'The agent uploaded no XML body for this attempt (it omits the body above 2 MB) — the sha256 and size above are all that was recorded.',
    };
}

/** One tag of the "What Tally answered" block. */
export interface AnswerTag {
    color: 'green' | 'red' | 'default';
    text: string;
}

export type SnapshotAnswer =
    | { kind: 'none' }
    | {
        kind: 'answer';
        tags: AnswerTag[];
        message: string | null;
        messageWithheld: string | null;
        raw: string | null;
    };

/**
 * Tally's answer as the block renders it. `none` when the agent had no
 * answer to report — a null `tally`, or one with every field null, which
 * is the inconclusive-timeout path (the XML went, nothing came back before
 * the agent gave up). Otherwise the verdict tag (accepted / rejected / no
 * verdict), then the CREATED and ERRORS counts the agent parsed — a count
 * it was not given is skipped rather than shown as 0 — then Tally's words
 * or the server's withheld note (FC-06, same rule as error_message), and
 * the raw response when the server chose to send it.
 */
export function snapshotAnswer(snapshot: TallySyncSnapshot): SnapshotAnswer {
    const tally: TallySnapshotAnswer | null = snapshot.tally;
    if (!tally || (tally.success === null && tally.created === null && tally.errors === null && !tally.message && !tally.message_withheld)) {
        return { kind: 'none' };
    }

    const tags: AnswerTag[] = [
        tally.success === true
            ? { color: 'green', text: 'accepted' }
            : tally.success === false
                ? { color: 'red', text: 'rejected' }
                : { color: 'default', text: 'no verdict' },
    ];
    if (typeof tally.created === 'number') tags.push({ color: 'default', text: `created ${tally.created}` });
    if (typeof tally.errors === 'number') tags.push({ color: tally.errors > 0 ? 'red' : 'default', text: `errors ${tally.errors}` });

    return {
        kind: 'answer',
        tags,
        message: tally.message || null,
        messageWithheld: tally.message_withheld || null,
        raw: typeof tally.raw === 'string' && tally.raw !== '' ? tally.raw : null,
    };
}
