import { describe, expect, it } from 'vitest';
import { EMPTY_FILTER_VALUE, columnSorter, compareValues, filterOptions, onFilterBy, sortBy } from './clientSort';

type Row = { name: string | null; qty: string | number | null; on: string | null; status: string | null };

const rows: Row[] = [
    { name: 'Cap 28mm', qty: '5000.0000', on: '2026-08-21', status: 'closed' },
    { name: 'Bottle 1L', qty: 12, on: '2026-08-29', status: 'draft' },
    { name: 'bottle 500ml', qty: null, on: null, status: null },
    { name: 'Carton 24', qty: '1,000', on: '2026-08-19', status: 'closed' },
];

describe('compareValues', () => {
    it('compares text case-insensitively and numerically inside strings', () => {
        expect(compareValues('Bottle 1L', 'bottle 500ml', 'text')).toBeLessThan(0);
        expect(compareValues('PO-10', 'PO-9', 'text')).toBeGreaterThan(0);
    });

    it('compares decimal strings and numbers as numbers, ignoring thousands separators', () => {
        expect(compareValues('5000.0000', 12, 'number')).toBeGreaterThan(0);
        expect(compareValues('1,000', '999', 'number')).toBeGreaterThan(0);
    });

    it('compares dates as instants', () => {
        expect(compareValues('2026-08-19', '2026-08-21', 'date')).toBeLessThan(0);
        expect(compareValues('2026-08-21T10:00:00Z', '2026-08-21', 'date')).toBeGreaterThan(0);
    });

    it('puts empties last', () => {
        expect(compareValues(null, 'a', 'text')).toBeGreaterThan(0);
        expect(compareValues('a', undefined, 'text')).toBeLessThan(0);
        expect(compareValues('', null, 'number')).toBe(0);
    });
});

describe('sortBy and columnSorter', () => {
    it('sorts rows by a nested getter', () => {
        const sorted = [...rows].sort(sortBy((row) => row.qty, 'number'));
        expect(sorted.map((row) => row.name)).toEqual(['Bottle 1L', 'Carton 24', 'Cap 28mm', 'bottle 500ml']);
    });

    it('keeps empties last when antd flips the comparator for descending', () => {
        const sorter = columnSorter<Row>((row) => row.on, 'date');
        // antd sorts descending by negating the comparator's result.
        const descending = [...rows].sort((a, b) => -sorter(a, b, 'descend'));
        expect(descending.map((row) => row.on)).toEqual(['2026-08-29', '2026-08-21', '2026-08-19', null]);

        const ascending = [...rows].sort((a, b) => sorter(a, b, 'ascend'));
        expect(ascending.map((row) => row.on)).toEqual(['2026-08-19', '2026-08-21', '2026-08-29', null]);
    });
});

describe('filterOptions and onFilterBy', () => {
    it('lists each distinct value once, labelled, sorted, with one choice for empties', () => {
        const options = filterOptions(rows, (row) => row.status, (value) => String(value).toUpperCase());
        expect(options).toEqual([
            { text: 'CLOSED', value: 'closed' },
            { text: 'DRAFT', value: 'draft' },
            { text: '—', value: EMPTY_FILTER_VALUE },
        ]);
    });

    it('matches rows by the stored value, and the empty choice by absence', () => {
        const matches = onFilterBy<Row>((row) => row.status);
        expect(rows.filter((row) => matches('closed', row)).map((row) => row.name)).toEqual(['Cap 28mm', 'Carton 24']);
        expect(rows.filter((row) => matches(EMPTY_FILTER_VALUE, row)).map((row) => row.name)).toEqual(['bottle 500ml']);
    });
});
