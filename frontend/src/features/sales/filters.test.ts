import axios from 'axios';
import { describe, expect, it } from 'vitest';
import {
    buildSalesQuery,
    documentNumber,
    documentPath,
    filtersFromSearchParams,
    hasActiveFilters,
    parseDocumentRef,
    searchParamsFromFilters,
    sortOptions,
    statusOptions,
} from './filters';

/**
 * The filter bars' contract with GET /sales/{sales-orders,deliveries,invoices},
 * pinned:
 *
 *  - only the keys THAT document's endpoint knows go on the wire (a delivery
 *    has no status; an order has no sales_order_id) — and a TanStack queryFn
 *    context contributes nothing;
 *  - empties never reach the URL (an empty Select must not send `status=`
 *    and turn "no filter" into "match nothing");
 *  - dates are YYYY-MM-DD, never an instant;
 *  - the URL and the filter object round-trip losslessly, so a pasted link
 *    reopens the same view;
 *  - the document number is spelled the way the server (and the voucher
 *    payload) spells it: SO-12 / DN-5 / INV-3.
 */

describe('buildSalesQuery', () => {
    it('drops every empty value — undefined, null, blank strings, non-finite numbers', () => {
        expect(buildSalesQuery('sales_order', {
            customer_id: undefined,
            status: '',
            from: '',
            to: '  ',
            q: '',
            item_id: Number.NaN,
            sort: undefined,
        })).toEqual({});

        expect(buildSalesQuery('sales_order', undefined)).toEqual({});
        expect(buildSalesQuery('delivery', null)).toEqual({});
    });

    it('keeps only the keys THAT document knows — status is not a delivery filter, sales_order_id is not an order filter', () => {
        const everything = {
            customer_id: 3,
            sales_order_id: 12,
            status: 'confirmed',
            from: '2026-08-01',
            to: '2026-08-17',
            item_id: 7,
            q: 'SO-12',
            sort: '-id',
            page: 2,
            per_page: 50,
        };

        expect(buildSalesQuery('sales_order', everything)).toEqual({
            customer_id: 3,
            status: 'confirmed',
            from: '2026-08-01',
            to: '2026-08-17',
            item_id: 7,
            q: 'SO-12',
            sort: '-id',
            page: 2,
            per_page: 50,
        });

        expect(buildSalesQuery('delivery', everything)).toEqual({
            customer_id: 3,
            sales_order_id: 12,
            from: '2026-08-01',
            to: '2026-08-17',
            item_id: 7,
            q: 'SO-12',
            sort: '-id',
            page: 2,
            per_page: 50,
        });

        expect(buildSalesQuery('invoice', everything)).toEqual({
            customer_id: 3,
            sales_order_id: 12,
            status: 'confirmed',
            from: '2026-08-01',
            to: '2026-08-17',
            item_id: 7,
            q: 'SO-12',
            sort: '-id',
            page: 2,
            per_page: 50,
        });
    });

    it('sends from/to as Y-m-d, cutting any time part off an ISO instant', () => {
        expect(buildSalesQuery('delivery', { from: '2026-08-01T00:00:00+05:30', to: '2026-08-17T23:59:59Z' })).toEqual({
            from: '2026-08-01',
            to: '2026-08-17',
        });
    });

    it('trims free text and drops a sort the document does not offer (the server would 422 it)', () => {
        expect(buildSalesQuery('sales_order', { q: '  so 12  ', sort: 'expected_date' })).toEqual({
            q: 'so 12',
            sort: 'expected_date',
        });
        // delivered_date is a delivery sort; on an order it is not a column
        expect(buildSalesQuery('sales_order', { sort: 'delivered_date' })).toEqual({});
        expect(buildSalesQuery('invoice', { sort: '-invoice_date' })).toEqual({ sort: '-invoice_date' });
        expect(buildSalesQuery('invoice', { sort: 'created_at' })).toEqual({});
    });

    it('serialises the way the shared axios instance does — plain key=value pairs', () => {
        const uri = axios.getUri({
            url: '/sales/deliveries',
            params: buildSalesQuery('delivery', { sales_order_id: 12, q: 'DN-5', page: 2 }),
        });

        expect(decodeURIComponent(uri)).toBe('/sales/deliveries?sales_order_id=12&q=DN-5&page=2');
    });

    it('ignores anything that is not a known filter key — a TanStack queryFn context contributes nothing', () => {
        // The pickers on the Deliveries / Invoices pages hand listSalesOrders
        // straight to useQuery as queryFn; TanStack calls it with its
        // context object.
        const context = { queryKey: ['sales', 'sales-orders'], signal: new AbortController().signal, meta: undefined };

        expect(buildSalesQuery('sales_order', context as never)).toEqual({});
    });
});

describe('hasActiveFilters', () => {
    it('is false for nothing, empties, and sort / paging alone', () => {
        expect(hasActiveFilters('sales_order', undefined)).toBe(false);
        expect(hasActiveFilters('sales_order', { status: '', q: '' })).toBe(false);
        expect(hasActiveFilters('sales_order', { sort: '-order_date', page: 3, per_page: 50 })).toBe(false);
    });

    it('is true as soon as one real filter is set — and only one THAT document sends', () => {
        expect(hasActiveFilters('sales_order', { status: 'draft' })).toBe(true);
        expect(hasActiveFilters('delivery', { customer_id: 4 })).toBe(true);
        expect(hasActiveFilters('invoice', { from: '2026-08-01' })).toBe(true);
        // a delivery has no status filter, so status alone narrows nothing there
        expect(hasActiveFilters('delivery', { status: 'draft' })).toBe(false);
    });
});

describe('URL round-trip', () => {
    it('writes filters into search params and reads the same filters back', () => {
        const filters = {
            customer_id: 3,
            status: 'issued',
            from: '2026-08-01',
            to: '2026-08-17',
            item_id: 7,
            q: 'INV-3',
            sort: '-invoice_date',
            page: 2,
            per_page: 50,
        };

        const params = searchParamsFromFilters('invoice', filters);

        expect(params.toString()).toBe(
            'customer_id=3&status=issued&from=2026-08-01&to=2026-08-17&item_id=7&q=INV-3&sort=-invoice_date&page=2&per_page=50',
        );
        expect(filtersFromSearchParams('invoice', params)).toEqual(filters);
    });

    it('reads numbers as numbers and drops junk — a hand-typed URL cannot poison the query', () => {
        const params = new URLSearchParams('customer_id=abc&item_id=-2&page=0&per_page=500&status=confirmed&sales_order_id=9');

        expect(filtersFromSearchParams('sales_order', params)).toEqual({ status: 'confirmed' });
        expect(filtersFromSearchParams('delivery', params)).toEqual({ sales_order_id: 9 });
    });

    it('caps per_page at the server\'s ceiling (100) rather than sending a value it would 422', () => {
        expect(filtersFromSearchParams('sales_order', new URLSearchParams('per_page=100'))).toEqual({ per_page: 100 });
        expect(filtersFromSearchParams('sales_order', new URLSearchParams('per_page=101'))).toEqual({});
    });

    it('leaves the URL empty when nothing is set', () => {
        expect(searchParamsFromFilters('delivery', {}).toString()).toBe('');
        expect(searchParamsFromFilters('delivery', { q: '', page: 1 }).toString()).toBe('');
    });

    it('does not carry a key the document does not know, either way', () => {
        expect(searchParamsFromFilters('delivery', { status: 'draft', sales_order_id: 2 }).toString()).toBe('sales_order_id=2');
        expect(filtersFromSearchParams('sales_order', new URLSearchParams('sales_order_id=2&status=draft'))).toEqual({ status: 'draft' });
    });
});

describe('documentNumber / documentPath', () => {
    it('spells each document the way the server and the voucher payload do', () => {
        expect(documentNumber('sales_order', 12)).toBe('SO-12');
        expect(documentNumber('delivery', 5)).toBe('DN-5');
        expect(documentNumber('invoice', 3)).toBe('INV-3');
    });

    it('links to the list page with the document opened', () => {
        expect(documentPath('sales_order', 12)).toBe('/sales/sales-orders?open=SO-12');
        expect(documentPath('delivery', 5)).toBe('/sales/deliveries?open=DN-5');
        expect(documentPath('invoice', 3)).toBe('/sales/invoices?open=INV-3');
    });
});

describe('parseDocumentRef', () => {
    it('reads every spelling the server searches by', () => {
        expect(parseDocumentRef('SO-12')).toEqual({ kind: 'sales_order', id: 12 });
        expect(parseDocumentRef('so 12')).toEqual({ kind: 'sales_order', id: 12 });
        expect(parseDocumentRef('so12')).toEqual({ kind: 'sales_order', id: 12 });
        expect(parseDocumentRef('DN-5')).toEqual({ kind: 'delivery', id: 5 });
        expect(parseDocumentRef('inv-3')).toEqual({ kind: 'invoice', id: 3 });
        expect(parseDocumentRef(' INV 3 ')).toEqual({ kind: 'invoice', id: 3 });
    });

    it('takes a bare number as the page\'s own document when told which page it is on, else nothing', () => {
        expect(parseDocumentRef('12', 'sales_order')).toEqual({ kind: 'sales_order', id: 12 });
        expect(parseDocumentRef('12')).toBeNull();
    });

    it('reads a separator only after a prefix — the same grammar as the server', () => {
        expect(parseDocumentRef('SO#12')).toEqual({ kind: 'sales_order', id: 12 });
        expect(parseDocumentRef('-12', 'sales_order')).toBeNull();
        expect(parseDocumentRef('#12', 'sales_order')).toBeNull();
        expect(parseDocumentRef('12abc', 'sales_order')).toBeNull();
    });

    it('is null for anything else — no guess', () => {
        expect(parseDocumentRef(null)).toBeNull();
        expect(parseDocumentRef(undefined)).toBeNull();
        expect(parseDocumentRef('')).toBeNull();
        expect(parseDocumentRef('PO-4')).toBeNull();
        expect(parseDocumentRef('SO-')).toBeNull();
        expect(parseDocumentRef('SO-0')).toBeNull();
        expect(parseDocumentRef('SO-1.5')).toBeNull();
    });
});

describe('sortOptions / statusOptions', () => {
    it('offers each document its own sortable columns, newest first as the first choice', () => {
        expect(sortOptions('sales_order').map((option) => option.value)).toEqual([
            '-id', 'id', '-order_date', 'order_date', '-expected_date', 'expected_date',
        ]);
        expect(sortOptions('delivery').map((option) => option.value)).toEqual([
            '-id', 'id', '-delivered_date', 'delivered_date',
        ]);
        expect(sortOptions('invoice').map((option) => option.value)).toEqual([
            '-id', 'id', '-invoice_date', 'invoice_date',
        ]);
    });

    it('offers statuses only where the document has one', () => {
        expect(statusOptions('sales_order').map((option) => option.value)).toEqual([
            'draft', 'confirmed', 'partially_delivered', 'completed', 'cancelled',
        ]);
        expect(statusOptions('invoice').map((option) => option.value)).toEqual(['draft', 'issued', 'paid']);
        expect(statusOptions('delivery')).toEqual([]);
    });
});
