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
