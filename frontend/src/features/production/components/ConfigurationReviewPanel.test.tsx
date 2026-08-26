import { isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Button, Select } from 'antd';
import { describe, expect, it } from 'vitest';
import { ConfigurationReviewFixCell, linkPlanFor, readOpenPreference, reviewHeadline } from '@/features/production/components/ConfigurationReviewPanel';
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

    // THE ARCHIVED IDENTITY'S MARKER IS PINNED WHERE IT IS DECIDED, not
    // here. The server says `archived` on a separate-product row's item and
    // product_item (ConfigurationReviewTest pins both payloads), and the
    // "(archived)" suffix it renders as belongs to
    // tallyIdentityLabelMarkingArchived — pinned in
    // productStandardsConfig.test.ts. The `Currently` column that prints it
    // is inline in the table's `columns` array, not extracted like this
    // cell; the wording is tested at the label, where it is worded once.
    // (The earlier position — that the coexisting packaging_no_identity row
    // made a marker unnecessary — did not survive pagination: past ten rows
    // that row can sit on a page the reader never opens.)

    it('does not claim the verdict is more settled than the stored columns make it', () => {
        // The old sentence overclaimed ("nothing here links it") in the
        // migration-window state, and a conditional replacement keyed on the
        // attach row's presence in the list was broken by an adversarial
        // pass in BOTH directions — a conflicting sibling packing (trashed
        // ones included) makes the backend refuse every attach while the
        // attach row still shows, and an inheriting sibling hides the attach
        // row while the drawer's attach still closes this row. So the cell
        // says only what is true in every state.
        const words = textIn(cell(SEPARATE_PRODUCT));

        expect(words).toContain('Nothing on this row links it');
        expect(words).toContain('if Tally truly carries two stock items');
        expect(words).toContain('re-judged from the stored columns on every read');
        expect(words).toContain('the same item at both ends is one product, not two');
        // What it must never do again: promise a close, or point at an
        // attach row it cannot know will accept — the writers guard that.
        expect(words).not.toContain('this row closes');
        expect(words).not.toContain('attach row is in this same list');
        expect(words).not.toContain('Nothing here links it');
    });

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

/**
 * DOES A LINK ACTUALLY GET SAVED, AND TO THE RIGHT PLACE (owner question,
 * 24-Aug-2026: "we need to check if we change those are getting saved").
 *
 * The two ends of that were already covered and the middle was not. The
 * backend PATCH is asserted twice over — PackagingIdentityOnlyTest proves the
 * row persists and no count moves, PackagingTallyIdentityTest asserts the
 * stored row. The cell's rendering is asserted above. What nothing asserted
 * was the step between: which endpoint gets which ids. That is precisely
 * where a save goes silently to the wrong row, and it is untestable through a
 * click here — this repo's vitest runs in node with no DOM.
 *
 * `linkPlanFor` exists so it is testable as data, and the component executes
 * what it returns rather than deciding again, so the two cannot drift.
 *
 * The one thing these tests do NOT prove: that the HTTP call leaves the
 * browser. Only a real click on live closes that, and it is stated here
 * rather than implied by a green suite.
 */
describe('what a link actually saves', () => {
    it('sends a packing identity to the packing route, with both ids', () => {
        expect(linkPlanFor(row({}), 77)).toEqual({
            endpoint: 'packaging_identity',
            standardId: 5,
            packagingId: 9,
            itemId: 77,
        });
    });

    it('sends an unattached standard to the attach route, without asking to confirm', () => {
        const unattached = row({
            // The standard-level row: no packaging, so fixTargetOf falls
            // through to 'attach_item'.
            kind: 'packaging_no_identity',
            packaging: null,
            item: null,
            fix_target: 'attach_item',
        });

        expect(linkPlanFor(unattached, 77)).toEqual({
            endpoint: 'attach_item',
            standardId: 5,
            itemId: 77,
            confirmRepoint: false,
        });
    });

    /**
     * The dangerous one. A standard that already names an item is a RE-POINT:
     * the backend refuses it without the flag (DEC-20260810-003) and the
     * person must confirm first. Both hang off `item !== null`, so this pins
     * that a populated item raises the flag — a plan that re-points silently
     * would be a wrong Tally identity on every FUTURE run of the product.
     */
    it('flags a re-point when the standard already names an item', () => {
        const attached = row({
            kind: 'packaging_no_identity',
            packaging: null,
            item: { id: 3, sku: 'BTL-P', name: 'Bottle A - Pouch' },
            fix_target: 'attach_item',
        });

        expect(linkPlanFor(attached, 77)).toEqual({
            endpoint: 'attach_item',
            standardId: 5,
            itemId: 77,
            confirmRepoint: true,
        });
    });

    /**
     * Rows that offer no control must plan no write. Asserted per row kind
     * rather than once, because each reaches `none` down a different branch
     * and a regression in any one of them would invent a save nobody offered
     * — on a separate-product row that would be the exact wrong Tally
     * mapping DEC-20260821-001 exists to prevent.
     */
    it.each([
        ['a separate-product row', SEPARATE_PRODUCT],
        ['an ambiguous-name row', row({ kind: 'packaging_ambiguous', fix_target: 'name_ambiguity' })],
        ['a provisional-SKU row', row({ kind: 'item_provisional_sku', standard: null, packaging: null, fix_target: 'item_sku' })],
    ])('plans no write at all for %s', (_label, r) => {
        expect(linkPlanFor(r, 77)).toEqual({ endpoint: 'none' });
    });

    it('plans no write when the row is missing the ids the route needs', () => {
        expect(linkPlanFor(row({ standard: null }), 77)).toEqual({ endpoint: 'none' });
        expect(linkPlanFor(row({ packaging: null }), 77)).toEqual({ endpoint: 'none' });
    });
});

/**
 * THE PANEL ARRIVES COLLAPSED (owner request, 24-Aug-2026).
 *
 * The filter directly beneath it already carries Production ready /
 * Incomplete / All with counts, so an expanded panel on every page load was
 * re-stating the chip beside it. Absent preference means collapsed; only an
 * explicit 'true' opens it, so a person who never opens it never sees the
 * table again.
 */
describe('the collapsed-by-default preference', () => {
    const withStorage = (store: Record<string, string> | null) => {
        const original = globalThis.window;
        // A private window THROWS on access rather than returning null, which
        // is the case that must not take the page down with it.
        (globalThis as { window?: unknown }).window = store === null
            ? { get localStorage(): never { throw new Error('blocked'); } }
            : { localStorage: { getItem: (k: string) => store[k] ?? null } };

        try {
            return readOpenPreference();
        } finally {
            (globalThis as { window?: unknown }).window = original;
        }
    };

    it('is collapsed when nothing was ever chosen', () => {
        expect(withStorage({})).toBe(false);
    });

    it('is collapsed when the person hid it', () => {
        expect(withStorage({ 'production.configurationReview.open': 'false' })).toBe(false);
    });

    it('is expanded only on an explicit true', () => {
        expect(withStorage({ 'production.configurationReview.open': 'true' })).toBe(true);
    });

    it('is collapsed, not crashed, when storage throws', () => {
        expect(withStorage(null)).toBe(false);
    });
});

/**
 * THE HEADLINE COUNT (the collapsed panel's one always-visible line).
 *
 * A separate-product row is not a missing identity — the identity IS the
 * finding — and the kinds deliberately coexist, so one packing can raise
 * three rows at once. Counting them all as "packing identities still
 * waiting on a person" inflated the number and mislabelled rows this screen
 * offers no action for. Each kind counts once, under its own name.
 */
describe('the headline count', () => {
    it('counts one packing raising three questions as one wrong-product packing and ONE identity', () => {
        // The exact state the backend's coexistence test builds:
        // separate-product + no-identity + ambiguous, all one packing. The
        // no-identity and shared-name rows are two questions about the same
        // unsettled identity, so they count once.
        const rows = [
            row({ kind: 'packaging_separate_product', fix_target: 'separate_product', missing: [] }),
            row({}),
            row({ kind: 'packaging_ambiguous', fix_target: 'name_ambiguity', ambiguity: { shared_name_count: 2 } }),
        ];

        expect(reviewHeadline(rows)).toBe('1 packing under the wrong product and 1 packing identity');
    });

    it('counts one packing whose identity is both missing and name-shared as ONE identity', () => {
        const rows = [
            row({}),
            row({ kind: 'packaging_ambiguous', fix_target: 'name_ambiguity', ambiguity: { shared_name_count: 2 } }),
        ];

        expect(reviewHeadline(rows)).toBe('1 packing identity');
    });

    it('keeps the standard-level identity distinct from a packaging-level one', () => {
        // The product's own identity and a packing's own identity are two
        // different identities even on one standard.
        const rows = [row({}), row({ packaging: null, fix_target: 'attach_item' })];

        expect(reviewHeadline(rows)).toBe('2 packing identities');
    });

    it('names every segment that is present, plural where plural', () => {
        const rows = [
            row({ kind: 'packaging_separate_product', fix_target: 'separate_product' }),
            row({ kind: 'packaging_separate_product', fix_target: 'separate_product' }),
            row({}),
            row({ kind: 'item_provisional_sku', standard: null, packaging: null, fix_target: 'item_sku' }),
        ];

        expect(reviewHeadline(rows)).toBe('2 packings under the wrong product, 1 packing identity and 1 provisional SKU');
    });

    it('reads exactly as it did before the new kind existed when none is present', () => {
        // Two DIFFERENT packings — two identities. (Two rows about the same
        // packing are the dedupe case above, not this one.)
        const second = row({ packaging: { id: 10, mode: 'direct_box', counts: null } });

        expect(reviewHeadline([row({}), second])).toBe('2 packing identities');
        expect(
            reviewHeadline([row({ kind: 'item_provisional_sku', standard: null, packaging: null, fix_target: 'item_sku' })]),
        ).toBe('1 provisional SKU');
    });
});
