import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import {
    STOCK_LIST_SPEC,
    encodeStockSort,
    parseStockSort,
    stockListNarrowed,
    stockListRequest,
    stockNoMatchLine,
    stockRange,
    stockSortFromTable,
    stockSortOrder,
} from './stockList';

describe('what the stock list asks the server for', () => {
    it('opens at item-name order, ascending, fifty to a page', () => {
        expect(stockListRequest({})).toEqual({ sort: 'item', direction: 'asc', per_page: 50 });
    });

    it('sends the needle, the warehouse and the page the URL carries', () => {
        const params = readListParams(new URLSearchParams('q=amber&warehouse_id=3&page=2&per_page=20&sort=-quantity'), STOCK_LIST_SPEC);

        expect(stockListRequest(params)).toEqual({
            q: 'amber',
            warehouse_id: 3,
            page: 2,
            per_page: 20,
            sort: 'quantity',
            direction: 'desc',
        });
    });

    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), STOCK_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(stockListRequest(params).sort).toBe('item');
    });

    it('trims the needle and sends nothing for an empty one', () => {
        expect(stockListRequest({ q: '  ' }).q).toBeUndefined();
        expect(stockListRequest({ q: ' cap ' }).q).toBe('cap');
    });
});

describe('the sort key', () => {
    it('round-trips every column in both directions', () => {
        for (const field of ['item', 'warehouse', 'quantity'] as const) {
            for (const direction of ['asc', 'desc'] as const) {
                const encoded = encodeStockSort(field, direction);
                expect(parseStockSort(encoded)).toEqual({ sort: field, direction });
            }
        }
    });

    it('leaves the default order off the URL', () => {
        expect(encodeStockSort('item', 'asc')).toBeUndefined();
        expect(encodeStockSort('item', 'desc')).toBe('-item');
        expect(encodeStockSort('quantity', 'asc')).toBe('quantity');
    });

    it('shows the active direction on the active column only', () => {
        expect(stockSortOrder('quantity', '-quantity')).toBe('descend');
        expect(stockSortOrder('item', '-quantity')).toBeNull();
        // The default order is item ascending even when the URL says nothing.
        expect(stockSortOrder('item', undefined)).toBe('ascend');
    });

    it('translates a header click and returns to item order when cleared', () => {
        expect(stockSortFromTable('quantity', 'descend')).toBe('-quantity');
        expect(stockSortFromTable('warehouse', 'ascend')).toBe('warehouse');
        expect(stockSortFromTable('quantity', null)).toBeUndefined();
        expect(stockSortFromTable('unit', 'ascend')).toBeUndefined();
    });
});

describe('narrowing', () => {
    it('counts search and warehouse, never sort or paging', () => {
        expect(stockListNarrowed({})).toBe(false);
        expect(stockListNarrowed({ sort: '-quantity', page: 3, per_page: 100 })).toBe(false);
        expect(stockListNarrowed({ q: 'cap' })).toBe(true);
        expect(stockListNarrowed({ warehouse_id: 2 })).toBe(true);
    });
});

describe('the range and the empty line', () => {
    it('places the page within the total the way the pager does', () => {
        expect(stockRange({ current_page: 1, last_page: 3, per_page: 50, total: 143 })).toEqual([1, 50]);
        expect(stockRange({ current_page: 3, last_page: 3, per_page: 50, total: 143 })).toEqual([101, 143]);
        expect(stockRange({ current_page: 1, last_page: 1, per_page: 50, total: 0 })).toEqual([0, 0]);
    });

    it('repeats the term, and the warehouse when one is chosen', () => {
        expect(stockNoMatchLine('zzz', undefined)).toBe('No balances match “zzz”.');
        expect(stockNoMatchLine('zzz', 'FG')).toBe('No balances match “zzz” in FG.');
        expect(stockNoMatchLine(undefined, 'FG')).toBe('No balances in FG.');
        expect(stockNoMatchLine('  ', undefined)).toBe('No balances match these filters.');
    });
});
