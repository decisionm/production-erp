import { describe, expect, it } from 'vitest';
import { columnSortOrder, sortParamFromSorter } from './tableSort';

describe('columnSortOrder', () => {
    it('shows the arrow only on the column the URL sorts by', () => {
        expect(columnSortOrder('order_date', 'order_date')).toBe('ascend');
        expect(columnSortOrder('order_date', '-order_date')).toBe('descend');
        expect(columnSortOrder('expected_date', '-order_date')).toBeNull();
    });

    it('falls back to the default order when the URL has none', () => {
        expect(columnSortOrder('id', undefined, '-id')).toBe('descend');
        expect(columnSortOrder('order_date', undefined, '-id')).toBeNull();
        expect(columnSortOrder('id', '  ', '-id')).toBe('descend');
    });

    it('shows nothing when there is neither a sort nor a default', () => {
        expect(columnSortOrder('id', undefined)).toBeNull();
    });
});

describe('sortParamFromSorter', () => {
    const allowed = ['id', 'order_date', 'expected_date'];

    it('writes the server spelling: bare for ascending, dash for descending', () => {
        expect(sortParamFromSorter({ columnKey: 'order_date', order: 'ascend' }, allowed)).toBe('order_date');
        expect(sortParamFromSorter({ columnKey: 'order_date', order: 'descend' }, allowed)).toBe('-order_date');
    });

    it('clears the URL for a cleared sort or for the default order', () => {
        expect(sortParamFromSorter({ columnKey: 'order_date', order: undefined }, allowed)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'id', order: 'descend' }, allowed, '-id')).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'id', order: 'ascend' }, allowed, '-id')).toBe('id');
    });

    it('refuses a field the server does not sort on', () => {
        expect(sortParamFromSorter({ columnKey: 'vendor', order: 'ascend' }, allowed)).toBeUndefined();
    });

    it('reads the first sorter of a multi-sorter array and falls back to field when there is no key', () => {
        expect(sortParamFromSorter([{ field: 'expected_date', order: 'descend' }], allowed)).toBe('-expected_date');
        expect(sortParamFromSorter([], allowed)).toBeUndefined();
    });
});
