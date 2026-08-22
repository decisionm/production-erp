import { isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Button, Select } from 'antd';
import { describe, expect, it } from 'vitest';
import { ConfigurationReviewFixCell } from '@/features/production/components/ConfigurationReviewPanel';
import type { ConfigurationReviewRow } from '@/features/production/types';

/**
 * WHAT THE REVIEW PANEL OFFERS TO DO ABOUT ONE ROW (DEC-20260821-001).
 *
 * The load-bearing claim is a NEGATIVE one — a `packaging_separate_product`
 * row must never render a Link or Attach control — and a negative claim about
 * rendering is worth nothing asserted about the branch instead of the output.
 * So the cell is called as a plain function (it is hook-free and entirely
 * prop-driven for this reason) and the element tree it returns is walked,
 * comparing `element.type` against the imported antd components BY REFERENCE.
 * A control nested three levels inside a Space or a Tooltip is still found;
 * a string match on the rendered words would not find it.
 *
 * No DOM is involved and none is available — this repo's vitest runs in node,
 * and `App.routes.test.tsx` is the precedent for reading a React tree as the
 * data it is until something renders it.
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

/** Every element type in the tree, in encounter order. */
const typesIn = (node: ReactNode): unknown[] => {
    const found: unknown[] = [];
    walk(node, (element) => found.push(element.type));
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

const CANDIDATES = [
    { id: 77, sku: 'BTL-T', name: 'Bottle A - Tray', guid: 'itm-tray' },
    { id: 78, sku: 'BTL-U', name: 'Bottle A - Tray Alt', guid: 'itm-tray-alt' },
];

const row = (overrides: Partial<ConfigurationReviewRow>): ConfigurationReviewRow => ({
    kind: 'packaging_no_identity',
    standard: { id: 5, product: 'BOTTLE A' },
    packaging: { id: 9, mode: 'tray', counts: { nos_per_pouch: null, pouches_per_box: null, nos_per_tray: 98, trays_per_box: 5, nos_per_box: 490 } },
    item: null,
    missing: ['tally_identity'],
    ambiguity: null,
    candidates: [],
    fix_target: 'packaging_item',
    ...overrides,
});

/** The cell, rendered with a person who HAS every permission and a candidate
 *  already chosen — the most permissive state there is, so nothing a test
 *  finds absent is absent merely because it was disabled away. */
const cell = (r: ConfigurationReviewRow) =>
    ConfigurationReviewFixCell({
        row: r,
        canManage: true,
        picked: CANDIDATES[0].id,
        onPick: () => undefined,
        busy: false,
        isBusyRow: false,
        onLink: () => undefined,
    });

const SEPARATE_PRODUCT = row({
    kind: 'packaging_separate_product',
    item: { id: 77, sku: 'BTL-T', name: 'Bottle A - Tray' },
    product_item: { id: 3, sku: 'BTL-P', name: 'Bottle A - Pouch' },
    missing: [],
    candidates: [],
    fix_target: 'separate_product',
});

describe('the separate-product row (DEC-20260821-001)', () => {
    it('offers no Link or Attach control and no candidate to select', () => {
        const types = typesIn(cell(SEPARATE_PRODUCT));

        expect(types).not.toContain(Button);
        expect(types).not.toContain(Select);
    });

    it('offers none even when candidates arrive anyway and the fix_target says to link', () => {
        // A payload that should never exist: candidates on a row that has no
        // link, and a fix_target naming the very write the decision withdrew
        // the authority for. The panel reads the KIND first, so the control
        // is absent as a property of the code rather than of the data.
        const spoofed = row({
            ...SEPARATE_PRODUCT,
            candidates: CANDIDATES,
            fix_target: 'packaging_item',
        });

        const types = typesIn(cell(spoofed));

        expect(types).not.toContain(Button);
        expect(types).not.toContain(Select);
    });

    it('offers none on a server too old to send fix_target at all', () => {
        const older = row({ ...SEPARATE_PRODUCT, candidates: CANDIDATES });
        delete (older as { fix_target?: unknown }).fix_target;

        const types = typesIn(cell(older));

        expect(types).not.toContain(Button);
        expect(types).not.toContain(Select);
    });

    it('says why, and says to go to Tally first rather than create a product here', () => {
        const words = textIn(cell(SEPARATE_PRODUCT));

        // The reason...
        expect(words).toContain('separate finished product');
        expect(words).toContain('not a second identity under this one');
        // ...the three steps, starting at the masters pull...
        expect(words).toContain('Pull the Tally masters');
        expect(words).toContain('create or attach its production');
        expect(words).toContain('then select that product');
        // ...why it starts there rather than at an Add Item button...
        expect(words).toContain('carries no Tally GUID and cannot post');
        // ...and that nothing recorded is touched.
        expect(words).toContain('history is never rewritten');
    });

    it('says the review is advisory without claiming the identity is the only record of what posted', () => {
        // The claim this replaced was FALSE, and load-bearing enough to have
        // blocked a release: a completed run freezes its own identity on the
        // entry (shift_production_entries.finished_item_id) and the voucher
        // payload is built from THAT column, never from the packaging's. The
        // reason to leave the identity alone is that it is the current
        // CONFIGURATION's evidence and this screen is read-only — not that
        // erasing it would destroy history it does not hold.
        const words = textIn(cell(SEPARATE_PRODUCT));

        expect(words).toContain('advisory');
        expect(words).toContain('keep the identity they froze');
        expect(words).toContain('history is never rewritten');
        expect(words).not.toMatch(/only record|the record of what/);
    });

    // NO TEST HERE FOR THE ARCHIVED IDENTITY, deliberately. The server now
    // resolves a separate-product row's own item INCLUDING a soft-deleted one
    // (tallyItemIncludingArchived), because the finding is about the stored
    // column — but the panel needs NO special label for that: the item's own
    // sku · name is the truthful answer, and the coexisting
    // packaging_no_identity row is what says it cannot post today. There is
    // nothing kind-specific to pin. The `Currently` column that prints it is
    // inline in the table's `columns` array, not extracted like this cell, and
    // extracting it purely to host a test would be a refactor nobody asked
    // for. What the payload carries is pinned on the server
    // (ConfigurationReviewTest::test_a_separate_product_row_names_its_identity_
    // even_when_that_item_row_is_archived).

    it('does not tell the reader the server refuses this — the review is read-only', () => {
        // The Start Batch modal's clause. True there, false here: these rows
        // already exist and nothing refuses them retroactively.
        expect(textIn(cell(SEPARATE_PRODUCT))).not.toContain('refuses this start');
    });
});

describe('the row kinds that predate the decision are unchanged', () => {
    it('still offers a Select and a Link on a packing with no identity', () => {
        const types = typesIn(cell(row({ candidates: CANDIDATES })));

        expect(types).toContain(Select);
        expect(types).toContain(Button);
        expect(textIn(cell(row({ candidates: CANDIDATES })))).toContain('Link');
    });

    it('still offers a Select and an Attach on a standard-level row', () => {
        const attach = row({ packaging: null, fix_target: 'attach_item', candidates: CANDIDATES });
        const types = typesIn(cell(attach));

        expect(types).toContain(Select);
        expect(types).toContain(Button);
        expect(textIn(cell(attach))).toContain('Attach');
    });

    it('still offers nothing on an ambiguous row, and still says why', () => {
        const ambiguous = row({
            kind: 'packaging_ambiguous',
            item: { id: 77, sku: 'BTL-T', name: 'Bottle A' },
            missing: [],
            ambiguity: { shared_name_count: 3 },
            candidates: CANDIDATES,
            fix_target: 'name_ambiguity',
        });

        const types = typesIn(cell(ambiguous));

        expect(types).not.toContain(Button);
        expect(types).not.toContain(Select);
        expect(textIn(cell(ambiguous))).toContain('A catalogue duplicate');
    });

    it('still offers nothing on a provisional-SKU row', () => {
        const sku = row({
            kind: 'item_provisional_sku',
            standard: null,
            packaging: null,
            item: { id: 12, sku: 'B.170ml Pet Bottle', name: 'B.170ml Pet Bottle' },
            missing: [],
            fix_target: 'item_sku',
        });

        const types = typesIn(cell(sku));

        expect(types).not.toContain(Button);
        expect(types).not.toContain(Select);
        expect(textIn(cell(sku))).toContain('Set the SKU on the item master');
    });
});
