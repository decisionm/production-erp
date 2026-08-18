import { statusColor, statusLabel } from '@/features/tally-sync/drawer';
import { documentNumber } from './filters';
import type { SalesDocumentKind, TallyLink, TallyMirror, TraceCarton } from './types';

/**
 * Pure helpers behind the Sales document drawer and the Tally column on
 * the list pages. No React, no axios — everything here is a function of
 * its arguments so it can be tested without a DOM (drawer.test.ts).
 */

/**
 * The Tally column's tag for one document — the SAME words and colours the
 * Tally Sync page uses for the same status, imported from there so the two
 * screens can never disagree about what "pending" is called. null when the
 * document has no entry at all: that is rendered as a dash, never as a
 * status, because "no entry" is a different fact from "waiting".
 */
export function tallyLinkTag(link: TallyLink | null | undefined): { color: string; label: string } | null {
    if (!link) return null;

    return { color: statusColor[link.status] ?? 'default', label: statusLabel[link.status] ?? link.status };
}

/**
 * The warning that rides beside every Sales / Delivery Note voucher: the
 * agent's builder for it is unvalidated against real Tally, and real sales
 * are invoiced in Tally regardless (DEC-20260809-003). The tag's text names
 * the decision the SERVER put on the flag — this file types no decision id
 * of its own — and the hover text is the server's note verbatim. null when
 * the flag is not raised.
 */
export function unvalidatedBuilderTag(link: TallyLink | null | undefined): { text: string; note: string } | null {
    const flag = link?.flags?.unvalidated_builder;
    if (!flag) return null;

    return {
        text: `unvalidated builder — Tally is the sales system of record${flag.decision ? ` (${flag.decision})` : ''}`,
        note: flag.note,
    };
}

const KIND_LABEL: Record<SalesDocumentKind, string> = {
    sales_order: 'Sales order',
    delivery: 'Delivery',
    invoice: 'Invoice',
};

/**
 * The drawer's title: the server's document_number when the document has
 * loaded, the same spelling worked out from the id while it is still
 * loading, and the bare kind when not even the id is known.
 */
export function documentTitle(
    kind: SalesDocumentKind,
    doc: { id: number; document_number?: string } | null | undefined,
    id?: number,
): string {
    if (doc?.document_number) return doc.document_number;
    const known = doc?.id ?? id;

    return known !== undefined ? documentNumber(kind, known) : KIND_LABEL[kind];
}

/**
 * The one-line reading of a delivery's cartons: how many boxes, how many
 * pieces, which batches. Pieces are decimal strings on the wire and are
 * only added up here for the caption. Batches are named only where a
 * carton carries one — nothing is inferred for a box that names none.
 */
export function cartonSummary(cartons: readonly TraceCarton[] | null | undefined): { cartons: number; pieces: number; batches: string[] } {
    const rows = cartons ?? [];
    const pieces = rows.reduce((sum, carton) => {
        const n = Number(carton.pieces);

        return sum + (Number.isFinite(n) ? n : 0);
    }, 0);
    const batches = [...new Set(rows.map((carton) => carton.batch_no).filter((batch): batch is string => typeof batch === 'string' && batch !== ''))];

    return { cartons: rows.length, pieces, batches };
}

/**
 * GET /sales/{documents}/{id} answers "Resource + trace". A JsonResource
 * puts the document under `data`; `trace` may ride inside it or beside it
 * (`->additional()`), and both readings are honoured so a resource-side
 * choice on the backend does not blank the drawer.
 */
export function unwrapShowResponse<T extends object>(body: { data: T; trace?: unknown }): T & { trace?: unknown } {
    const doc = body.data as T & { trace?: unknown };
    if (doc.trace === undefined && body.trace !== undefined) {
        return { ...doc, trace: body.trace };
    }

    return doc;
}

/** GET /sales/tally-mirror answers the object bare or wrapped in `data`; both are read. */
export function unwrapMirrorResponse(body: TallyMirror | { data: TallyMirror }): TallyMirror {
    return 'data' in body && !('mirrored' in body) ? body.data : (body as TallyMirror);
}

/**
 * What an EMPTY table says, judged on the query's state — never on the row
 * count alone. A list that could not be read (a 403 for a login without
 * Sales access, a 500) has NO rows, and before this the table wrote "No sales
 * orders match these filters." over that hole: a permission error read as an
 * empty result, on the one page whose job is to say honestly what is and is
 * not here. `pending` covers TanStack's paused retry too (a hidden tab), so
 * the reader sees "still reading" rather than a verdict.
 */
export function listEmptyText(
    state: { isPending: boolean; isError: boolean; error?: unknown },
    kind: SalesDocumentKind,
    filtersActive: boolean,
): string {
    const noun = kind === 'sales_order' ? 'sales orders' : kind === 'delivery' ? 'deliveries' : 'invoices';

    if (state.isError) {
        return `Could not read ${noun}: ${errorSentence(state.error)}`;
    }

    if (state.isPending) {
        return `Reading ${noun}…`;
    }

    return filtersActive ? `No ${noun} match these filters.` : `No ERP-originated ${noun} yet.`;
}

/** The server's own sentence when it sent one; the transport's otherwise. */
export function errorSentence(error: unknown): string {
    const anyErr = error as { response?: { status?: number; data?: { message?: string } }; message?: string } | undefined;
    const serverMessage = anyErr?.response?.data?.message;
    const status = anyErr?.response?.status;

    if (serverMessage) {
        return status ? `${serverMessage} (${status})` : serverMessage;
    }

    return anyErr?.message ?? 'unknown error';
}

/**
 * What the "invoiced" figure counts. It sums EVERY invoice raised against the
 * order in the ERP, drafts included — a draft is a document, but it is queued
 * for Tally only once issued, and on this factory real invoices are raised in
 * Tally anyway (DEC-20260809-003). Said wherever the number is printed, so a
 * reader never takes "invoiced 500" for "500 billed and in Tally". Whether a
 * draft should count at all is an open factory question, not this file's.
 */
export const INVOICED_CAPTION =
    'Invoiced counts every invoice raised against the order in this ERP, drafts included — a draft is queued for Tally only once issued.';
