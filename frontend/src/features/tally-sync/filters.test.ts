import axios from 'axios';
import { describe, expect, it } from 'vitest';
import {
    buildEntryQuery,
    catalogueNote,
    categoryFilterOptions,
    categoryLabel,
    hasActiveFilters,
    unclassifiedCount,
} from './filters';
import type { TallySyncCategoryCount, TallyTransactionCategory } from './types';

/**
 * The filter bar's contract with GET /tally-sync/entries, pinned:
 *
 *  - empties never reach the URL (an empty Select must not send `status=`
 *    and turn "no filter" into "match nothing");
 *  - arrays go out in the bracket form Laravel reads as arrays, through
 *    the SAME default serializer the shared axios instance uses;
 *  - business dates are YYYY-MM-DD, never an instant;
 *  - the honesty copy (category suffix, "lives in Tally" line) says
 *    exactly what the backend catalogue says, no more.
 */

const category = (over: Partial<TallyTransactionCategory> = {}): TallyTransactionCategory => ({
    key: 'sales_invoice',
    label: 'Sales — Invoice',
    wire_voucher_type: 'Sales',
    source: 'erp',
    erp_build: 'built',
    direction: 'erp_to_tally',
    source_module: 'sales',
    erp_label_differs_from_wire: false,
    ...over,
});

describe('buildEntryQuery', () => {
    it('drops every empty value — undefined, null, blank strings, empty arrays, held=false', () => {
        expect(buildEntryQuery({
            status: [],
            category: undefined,
            voucher_type: ['', '   '],
            from: '',
            to: '  ',
            q: '',
            held: false,
            shift_id: undefined,
        })).toEqual({});

        expect(buildEntryQuery(undefined)).toEqual({});
        expect(buildEntryQuery(null)).toEqual({});
    });

    it('passes arrays through as arrays (not comma-joined), minus blank members', () => {
        expect(buildEntryQuery({ status: ['failed', 'pending'], category: ['sales_invoice', ''] })).toEqual({
            status: ['failed', 'pending'],
            category: ['sales_invoice'],
        });
    });

    it('serialises arrays the way the shared axios instance does — Laravel bracket form', () => {
        // @/lib/api.ts sets no paramsSerializer, so axios's default applies
        // to it and to this bare instance alike; this pins the wire shape
        // the backend's `status.*` validation actually receives.
        const uri = axios.getUri({
            url: '/tally-sync/entries',
            params: buildEntryQuery({ status: ['failed', 'pending'], q: 'SPE-42' }),
        });

        expect(decodeURIComponent(uri)).toBe('/tally-sync/entries?status[]=failed&status[]=pending&q=SPE-42');
    });

    it('sends from/to as Y-m-d, cutting any time part off an ISO instant', () => {
        expect(buildEntryQuery({ from: '2026-08-01', to: '2026-08-17' })).toEqual({
            from: '2026-08-01',
            to: '2026-08-17',
        });

        expect(buildEntryQuery({ from: '2026-08-01T00:00:00+05:30', to: '2026-08-17T23:59:59Z' })).toEqual({
            from: '2026-08-01',
            to: '2026-08-17',
        });
    });

    it('sends held=true as 1 (the wire boolean convention) and never sends held=false', () => {
        expect(buildEntryQuery({ held: true })).toEqual({ held: 1 });
        expect(buildEntryQuery({ held: false })).toEqual({});
    });

    it('keeps numbers, trimmed text, direction and sort', () => {
        expect(buildEntryQuery({
            shift_id: 2,
            work_center_id: 7,
            q: '  Relpet  ',
            direction: 'erp_to_tally',
            sort: 'status_rank',
        })).toEqual({
            shift_id: 2,
            work_center_id: 7,
            q: 'Relpet',
            direction: 'erp_to_tally',
            sort: 'status_rank',
        });
    });

    it('ignores anything that is not a known filter key — a TanStack queryFn context contributes nothing', () => {
        // Dashboard and Live Monitor hand list functions straight to
        // useQuery as queryFn; TanStack calls them with its context object.
        const context = { queryKey: ['tally-sync', 'entries', 'all'], signal: new AbortController().signal, meta: undefined };

        expect(buildEntryQuery(context as never)).toEqual({});
    });
});

describe('hasActiveFilters', () => {
    it('is false for nothing, empties, and sort alone', () => {
        expect(hasActiveFilters(undefined)).toBe(false);
        expect(hasActiveFilters({ status: [], q: '' })).toBe(false);
        expect(hasActiveFilters({ sort: 'status_rank' })).toBe(false);
    });

    it('is true as soon as one real filter is set', () => {
        expect(hasActiveFilters({ status: ['failed'] })).toBe(true);
        expect(hasActiveFilters({ held: true })).toBe(true);
        expect(hasActiveFilters({ from: '2026-08-01' })).toBe(true);
    });
});

describe('categoryLabel', () => {
    it('is the backend label as-is when the ERP label IS the wire voucher type', () => {
        expect(categoryLabel(category())).toBe('Sales — Invoice');
    });

    it('says out loud what actually posts when the ERP label differs from the wire', () => {
        expect(categoryLabel(category({
            key: 'production_stock_journal_batch',
            label: 'Production — Stock Journal (per batch, ERP label "Manufacturing Journal")',
            wire_voucher_type: 'Stock Journal',
            erp_label_differs_from_wire: true,
        }))).toBe('Production — Stock Journal (per batch, ERP label "Manufacturing Journal") · posts as Stock Journal');
    });

    it('never invents a wire type: differs=true with no wire type shows the label alone', () => {
        expect(categoryLabel(category({ erp_label_differs_from_wire: true, wire_voucher_type: null }))).toBe('Sales — Invoice');
    });

    it('is a dash for a missing category', () => {
        expect(categoryLabel(null)).toBe('—');
        expect(categoryLabel(undefined)).toBe('—');
    });
});

describe('catalogueNote', () => {
    // The backend catalogue AS SERVED (TallyTransactionCategory, in its case
    // order), with the counts the summary attaches: numbers for ERP rows,
    // null (never 0) for the rest. Two axes per row: where it lives (source)
    // and what the ERP built (erp_build). Purchase Order is an ERP-BUILT,
    // STAGED row since Phase 6 (source 'erp', erp_build 'built'; live posting
    // owner-gated, flag off — an honest 0), no longer Tally-only/planned.
    const catalogue: TallySyncCategoryCount[] = [
        { key: 'production_stock_journal_shift', label: 'Production — Stock Journal (per shift)', wire_voucher_type: 'Stock Journal', source: 'erp', erp_build: 'built', count: 12 },
        { key: 'sales_invoice', label: 'Sales — Invoice', wire_voucher_type: 'Sales', source: 'erp', erp_build: 'built', count: 0 },
        { key: 'purchase_order', label: 'Procurement — Purchase Order (staged; live posting owner-gated, Q35 — flag off)', wire_voucher_type: 'Purchase Order', source: 'erp', erp_build: 'built', count: 0 },
        { key: 'purchase', label: 'Purchase (lives in Tally)', wire_voucher_type: 'Purchase', source: 'tally', erp_build: 'none', count: null },
        { key: 'payment', label: 'Payment (lives in Tally)', wire_voucher_type: 'Payment', source: 'tally', erp_build: 'none', count: null },
        { key: 'receipt', label: 'Receipt (lives in Tally)', wire_voucher_type: 'Receipt', source: 'tally', erp_build: 'none', count: null },
        { key: 'contra', label: 'Contra (lives in Tally)', wire_voucher_type: 'Contra', source: 'tally', erp_build: 'none', count: null },
        { key: 'credit_note', label: 'Credit Note (lives in Tally)', wire_voucher_type: 'Credit Note', source: 'tally', erp_build: 'none', count: null },
        { key: 'debit_note', label: 'Debit Note (lives in Tally)', wire_voucher_type: 'Debit Note', source: 'tally', erp_build: 'none', count: null },
        { key: 'sales_order', label: 'Sales Order (no such voucher type in the books; sales are invoiced there — DEC-20260809-003)', wire_voucher_type: null, source: 'absent', erp_build: 'none', count: null },
        { key: 'unknown', label: 'Unknown', wire_voucher_type: null, source: 'unknown', erp_build: 'none', count: 0 },
    ];

    // ONE explicit synthetic 'planned' row — no served category is planned
    // today (Purchase Order was, before Phase 6 built it) — so the planned
    // clause stays covered. Every value is invented for the test.
    const syntheticPlanned: TallySyncCategoryCount = {
        key: 'synthetic_planned',
        label: 'Synthetic Voucher (lives in Tally; ERP-originated version planned, Phase 99)',
        wire_voucher_type: 'Synthetic Voucher',
        source: 'tally',
        erp_build: 'planned',
        count: null,
    };

    it('says two things for the served catalogue: what lives in Tally (Purchase Order NOT among them — it is ERP-built and staged), what is absent', () => {
        expect(catalogueNote(catalogue)).toEqual([
            'Lives in Tally, not mirrored: Purchase · Payment · Receipt · Contra · Credit Note · Debit Note',
            'Sales Order: no such voucher type in Tally — sales are invoiced there (DEC-20260809-003)',
        ]);
        expect(catalogueNote(catalogue).join('\n')).not.toContain('Purchase Order');
    });

    it('says a third thing when a row is planned: the plan, with the phase read out of the server\'s own label', () => {
        expect(catalogueNote([...catalogue, syntheticPlanned])).toEqual([
            'Lives in Tally, not mirrored: Purchase · Payment · Receipt · Contra · Credit Note · Debit Note · Synthetic Voucher',
            'Synthetic Voucher: ERP-originated version planned (Phase 99)',
            'Sales Order: no such voucher type in Tally — sales are invoiced there (DEC-20260809-003)',
        ]);
    });

    it('never lists Sales Order as living in Tally — no such voucher type is in the books', () => {
        const [inTally] = catalogueNote(catalogue);

        expect(inTally).not.toContain('Sales Order');
    });

    it('leaves ERP-built and unknown rows out, and never renders a zero — those are the table, not the note', () => {
        const note = catalogueNote(catalogue).join('\n');

        expect(note).not.toContain('Invoice');
        expect(note).not.toContain('per shift');
        expect(note).not.toContain('Unknown');
        expect(note).not.toMatch(/\b0\b/);
    });

    it('is empty when there is nothing to say (no catalogue yet, or only ERP rows)', () => {
        expect(catalogueNote(undefined)).toEqual([]);
        expect(catalogueNote([])).toEqual([]);
        expect(catalogueNote(catalogue.filter((row) => row.source === 'erp'))).toEqual([]);
    });

    it('offers only categories an entry can have — the ERP rows (the staged Purchase Order among them) AND unknown, never Tally-only or absent', () => {
        expect(categoryFilterOptions(catalogue).map((option) => option.value)).toEqual([
            'production_stock_journal_shift',
            'sales_invoice',
            'purchase_order',
            'unknown',
        ]);
        expect(categoryFilterOptions([...catalogue, syntheticPlanned]).map((option) => option.value)).not.toContain('synthetic_planned');
        expect(categoryFilterOptions(catalogue).at(-1)).toEqual({ value: 'unknown', label: 'Unknown' });
        expect(categoryFilterOptions(undefined)).toEqual([]);
    });

    it('reads the unclassified count off the unknown row, and 0 when there is none yet', () => {
        expect(unclassifiedCount(catalogue)).toBe(0);
        expect(unclassifiedCount(catalogue.map((row) => (row.key === 'unknown' ? { ...row, count: 2 } : row)))).toBe(2);
        expect(unclassifiedCount(undefined)).toBe(0);
        expect(unclassifiedCount(catalogue.filter((row) => row.key !== 'unknown'))).toBe(0);
    });

    it('does not invent a phase or a decision when the label carries none', () => {
        expect(catalogueNote([
            { ...syntheticPlanned, label: 'Synthetic Voucher (lives in Tally)' },
            { key: 'sales_order', label: 'Sales Order (no such voucher type in the books)', wire_voucher_type: null, source: 'absent', erp_build: 'none', count: null },
        ])).toEqual([
            'Lives in Tally, not mirrored: Synthetic Voucher',
            'Synthetic Voucher: ERP-originated version planned',
            'Sales Order: no such voucher type in Tally',
        ]);
    });
});
