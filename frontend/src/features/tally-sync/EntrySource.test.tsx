import { isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { EntrySourceRecord } from '@/features/tally-sync/EntryDrawer';
import { EntrySourceCell } from '@/features/tally-sync/pages/TallySyncPage';
import { sourceLink } from '@/features/tally-sync/drawer';
import { documentPath, parseDocumentRef } from '@/features/sales/filters';
import type { TallySyncEntry } from '@/features/tally-sync/types';

/**
 * WHAT THE QUEUE ROW AND THE DRAWER OFFER TO OPEN (review BLOCK, PR #27).
 *
 * The claim under test is a NEGATIVE one — no production link on a row
 * that is not production — and a negative claim about rendering is worth
 * nothing asserted about a branch instead of the output. The table's cell
 * printed `<Link to="/production/shift-production">` unconditionally while
 * the drawer beside it asked a resolver; a test of the resolver alone
 * would have passed throughout.
 *
 * So both cells are called as plain functions (hook-free and entirely
 * prop-driven for this reason) and the element trees they return are
 * walked, matching `element.type` against the imported `Link` BY
 * REFERENCE — a Link nested inside a Space or a Typography.Text is still
 * found, where a string match on the rendered words would not be. The
 * ConfigurationReviewPanel.test.tsx precedent; no DOM is involved and none
 * is available, since this repo's vitest runs in node.
 *
 * The two are then compared to each other AND to sourceLink() on the same
 * entry: one matrix, one answer, or the test fails.
 */

const walk = (node: ReactNode, visit: (element: ReactElement) => void): void => {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!isValidElement(node)) return;
    visit(node);
    walk((node.props as { children?: ReactNode }).children, visit);
};

/** Every `to` a react-router Link in this tree points at, in encounter order. */
const linksIn = (node: ReactNode): string[] => {
    const found: string[] = [];
    walk(node, (element) => {
        if (element.type === Link) found.push(String((element.props as { to?: unknown }).to));
    });

    return found;
};

/** Every string of rendered text in the tree, joined — for reading the words. */
const textIn = (node: ReactNode): string => {
    const parts: string[] = [];
    const collect = (child: ReactNode): void => {
        if (typeof child === 'string' || typeof child === 'number') {
            parts.push(String(child));
            return;
        }
        if (Array.isArray(child)) {
            child.forEach(collect);
            return;
        }
        if (isValidElement(child)) collect((child.props as { children?: ReactNode }).children);
    };
    collect(node);

    return parts.join(' ');
};

/**
 * A queue row. Names are synthetic throughout this file — no vendor, no
 * customer, no Tally ledger and no rate appears in a fixture (FC-06).
 */
const entry = (over: Partial<TallySyncEntry> = {}): TallySyncEntry => ({
    id: 11,
    syncable_id: 4,
    syncable_type: 'SomeUnknownMorph',
    tally_voucher_type: 'Stock Journal',
    category: {
        key: 'unknown',
        label: 'Unknown',
        wire_voucher_type: null,
        source: 'unknown',
        erp_build: 'none',
        direction: 'erp_to_tally',
        source_module: null,
        erp_label_differs_from_wire: false,
    },
    business_date: '2026-08-25',
    document_number: 'DOC-4',
    party: null,
    item_summary: null,
    payload: {},
    status: 'pending',
    attempts: 0,
    error_message: null,
    synced_at: null,
    delivered_at: null,
    released_at: null,
    hold: null,
    created_at: '2026-08-25T04:00:00+00:00',
    ...over,
});

/** The same entry with one category key on it and nothing else that hints at a source. */
const keyed = (key: string, over: Partial<TallySyncEntry> = {}): TallySyncEntry =>
    entry({ ...over, category: { ...entry().category, key, ...(over.category ?? {}) } });

/** Every category key the server can send (TallyTransactionCategory, 15 cases). */
const CATEGORY_KEYS = [
    'production_stock_journal_shift',
    'production_stock_journal_batch',
    'sales_invoice',
    'delivery_note',
    'receipt_note',
    'journal',
    'purchase_order',
    'purchase',
    'payment',
    'receipt',
    'contra',
    'credit_note',
    'debit_note',
    'sales_order',
    'unknown',
];

/** The rows both cells are read over: every key, plus the ids and shapes that must not resolve. */
const ROWS: { name: string; entry: TallySyncEntry }[] = [
    ...CATEGORY_KEYS.map((key) => ({ name: key, entry: keyed(key) })),
    { name: 'receipt_note with id 0', entry: keyed('receipt_note', { syncable_id: 0 }) },
    { name: 'sales_invoice with a negative id', entry: keyed('sales_invoice', { syncable_id: -3 }) },
    { name: 'purchase_order with a fractional id', entry: keyed('purchase_order', { syncable_id: 2.5 }) },
    { name: 'delivery_note with no id at all', entry: keyed('delivery_note', { syncable_id: undefined as unknown as number }) },
    { name: 'production with id 0', entry: keyed('production_stock_journal_shift', { syncable_id: 0 }) },
    { name: 'a key no catalogue has', entry: keyed('not_a_category') },
    { name: 'unknown on a Shift morph', entry: keyed('unknown', { syncable_type: 'Shift' }) },
    { name: 'unknown on a ShiftProductionEntry morph', entry: keyed('unknown', { syncable_type: 'ShiftProductionEntry' }) },
    { name: 'no category at all', entry: entry({ category: undefined as unknown as TallySyncEntry['category'] }) },
];

describe('the queue cell and the drawer record', () => {
    it('offer the same destination as each other, and it is the matrix\'s', () => {
        for (const row of ROWS) {
            const expected = sourceLink(row.entry);
            const cell = linksIn(EntrySourceCell({ entry: row.entry }));
            const record = linksIn(EntrySourceRecord({ entry: row.entry }));

            expect(cell, row.name).toEqual(record);
            expect(cell, row.name).toEqual(expected ? [expected.to] : []);
        }
    });

    it('render NO link at all where the matrix has none — not a dead one, not a guess', () => {
        for (const row of ROWS.filter((row) => sourceLink(row.entry) === null)) {
            expect(linksIn(EntrySourceCell({ entry: row.entry })), row.name).toEqual([]);
            expect(linksIn(EntrySourceRecord({ entry: row.entry })), row.name).toEqual([]);
        }
    });

    it('put no production link on any row that is not production', () => {
        // The defect this replaces: the table's cell rendered "Open
        // production entries" on EVERY row it drew, so a Receipt Note, a
        // Sales invoice and an unclassified voucher all offered the floor's
        // production list.
        const nonProduction = ROWS.filter((row) => !row.entry.category?.key?.startsWith('production_'));

        for (const row of nonProduction) {
            for (const to of [...linksIn(EntrySourceCell({ entry: row.entry })), ...linksIn(EntrySourceRecord({ entry: row.entry }))]) {
                expect(to, row.name).not.toContain('/production/');
            }
        }

        expect(nonProduction.length).toBeGreaterThan(10);
    });

    it('do link a production row to the production entries, both of them', () => {
        for (const key of ['production_stock_journal_shift', 'production_stock_journal_batch']) {
            expect(linksIn(EntrySourceCell({ entry: keyed(key) })), key).toEqual(['/production/shift-production']);
            expect(linksIn(EntrySourceRecord({ entry: keyed(key) })), key).toEqual(['/production/shift-production']);
        }
    });

    it('still name the source record, the batch and the shift', () => {
        const batch = keyed('production_stock_journal_batch', {
            syncable_type: 'ShiftProductionEntry',
            syncable_id: 12,
            payload: { batch_number: 'B-77', shift: 'A' },
        });
        const shift = keyed('production_stock_journal_shift', {
            syncable_type: 'Shift',
            syncable_id: 9,
            payload: { shift: 'A' },
        });

        for (const cell of [EntrySourceCell({ entry: batch }), EntrySourceRecord({ entry: batch })]) {
            expect(textIn(cell)).toContain('ShiftProductionEntry');
            expect(textIn(cell)).toContain('12');
            expect(textIn(cell)).toContain('B-77');
            // The batch number wins the line; the shift is not said twice.
            expect(textIn(cell)).not.toContain('A shift');
        }

        for (const cell of [EntrySourceCell({ entry: shift }), EntrySourceRecord({ entry: shift })]) {
            expect(textIn(cell)).toContain('Shift');
            expect(textIn(cell)).toContain('9');
            expect(textIn(cell)).toContain('shift');
        }
    });

    it('say what the link opens, in the words of the record it opens', () => {
        const label = (key: string) => {
            const found: string[] = [];
            walk(EntrySourceCell({ entry: keyed(key) }), (element) => {
                if (element.type === Link) found.push(textIn(element));
            });

            return found.join('');
        };

        expect(label('production_stock_journal_shift')).toBe('Open production entries');
        expect(label('receipt_note')).toBe('Open the goods receipt');
        expect(label('purchase_order')).toBe('Open the purchase order');
        expect(label('sales_invoice')).toBe('Open the invoice');
        expect(label('delivery_note')).toBe('Open the delivery note');
    });
});

/**
 * THE PATHS ARE THE DESTINATION PAGES' OWN.
 *
 * A link is only worth offering if the page it lands on reads it. Each
 * spelling below is checked against the code that parses it, not against
 * the string this feature happens to build — so a rename on the other side
 * fails here rather than on the floor.
 */
describe('the destinations', () => {
    it('spell a sales document the way the sales list writes and reads one', () => {
        expect(sourceLink(keyed('sales_invoice', { syncable_id: 4 }))?.to).toBe(documentPath('invoice', 4));
        expect(sourceLink(keyed('delivery_note', { syncable_id: 5 }))?.to).toBe(documentPath('delivery', 5));

        // …and what useSalesListParams reads back out of `?open=` is the
        // document the row was actually about.
        expect(parseDocumentRef('INV-4', 'invoice')).toEqual({ kind: 'invoice', id: 4 });
        expect(parseDocumentRef('DN-5', 'delivery')).toEqual({ kind: 'delivery', id: 5 });
    });

    it('name the query parameters the procurement pages look for', () => {
        const query = (to: string) => new URLSearchParams(to.slice(to.indexOf('?')));

        // GoodsReceiptsPage: `Number(searchParams.get('grn'))`.
        expect(query(sourceLink(keyed('receipt_note', { syncable_id: 7 }))!.to).get('grn')).toBe('7');
        // usePurchaseOrderListParams: `positiveInt(searchParams.get('open'))`
        // — `?po=` is its LEGACY alias, so a new link writes `open`.
        expect(query(sourceLink(keyed('purchase_order', { syncable_id: 3 }))!.to).get('open')).toBe('3');
        expect(query(sourceLink(keyed('purchase_order', { syncable_id: 3 }))!.to).get('po')).toBeNull();
    });

    it('go to paths the router actually serves', () => {
        // Every one of these is in App.routes.test.tsx's pinned route table.
        const paths = CATEGORY_KEYS
            .map((key) => sourceLink(keyed(key))?.to)
            .filter((to): to is string => typeof to === 'string')
            .map((to) => to.split('?')[0]);

        expect([...new Set(paths)].sort()).toEqual([
            '/procurement/goods-receipts',
            '/procurement/purchase-orders',
            '/production/shift-production',
            '/sales/deliveries',
            '/sales/invoices',
        ]);
    });
});
