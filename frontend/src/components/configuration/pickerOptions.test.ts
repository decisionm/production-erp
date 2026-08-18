import { describe, expect, it } from 'vitest';
import { RETIRED_OPTION_MARKER, activePickerOptions, retiredOptionLabel } from './pickerOptions';

interface Row {
    id: number;
    code: string;
    live: boolean;
}

const rows: Row[] = [
    { id: 1, code: 'SCR-01', live: true },
    { id: 2, code: 'SCR-02', live: false },
    { id: 3, code: 'SCR-03', live: true },
];

const spec = {
    isActive: (row: Row) => row.live,
    option: (row: Row) => ({ value: row.id, label: row.code }),
};

describe('activePickerOptions', () => {
    it('does not offer a retired row for a new record', () => {
        expect(activePickerOptions(rows, spec).map((o) => o.value)).toEqual([1, 3]);
    });

    it('leaves the active rows in the server order, with the label untouched', () => {
        expect(activePickerOptions(rows, spec)).toEqual([
            { value: 1, label: 'SCR-01' },
            { value: 3, label: 'SCR-03' },
        ]);
    });

    it('offers nothing at all when the list has not arrived', () => {
        expect(activePickerOptions(undefined, spec)).toEqual([]);
    });

    it('keeps the retired row the record ALREADY names — visible, marked, and not selectable', () => {
        const options = activePickerOptions(rows, { ...spec, keep: 2 });

        expect(options).toHaveLength(3);
        expect(options[2]).toEqual({ value: 2, label: `SCR-02 ${RETIRED_OPTION_MARKER}`, disabled: true });
    });

    it('puts the kept retired row last, never among the rows that may be chosen', () => {
        const options = activePickerOptions(rows, { ...spec, keep: 2 });

        expect(options.map((o) => o.value)).toEqual([1, 3, 2]);
    });

    it('does not duplicate or disable a kept row that is still active', () => {
        const options = activePickerOptions(rows, { ...spec, keep: 1 });

        expect(options).toEqual([
            { value: 1, label: 'SCR-01' },
            { value: 3, label: 'SCR-03' },
        ]);
    });

    it('adds nothing when the kept value names no row in the list', () => {
        expect(activePickerOptions(rows, { ...spec, keep: 99 }).map((o) => o.value)).toEqual([1, 3]);
    });

    it('treats a null or undefined keep as "this is a new record"', () => {
        expect(activePickerOptions(rows, { ...spec, keep: null }).map((o) => o.value)).toEqual([1, 3]);
        expect(activePickerOptions(rows, { ...spec, keep: undefined }).map((o) => o.value)).toEqual([1, 3]);
    });

    it('reads a status-enum master through the caller predicate, not a boolean it assumes', () => {
        const molds = [
            { id: 7, name: 'M-7', status: 'active' },
            { id: 8, name: 'M-8', status: 'under_repair' },
            { id: 9, name: 'M-9', status: 'retired' },
        ];

        const options = activePickerOptions(molds, {
            isActive: (m) => m.status !== 'retired',
            option: (m) => ({ value: m.id, label: m.name }),
        });

        expect(options.map((o) => o.value)).toEqual([7, 8]);
    });

    it('keeps a retired row only once even if the list repeats the id', () => {
        const duplicated = [...rows, { id: 2, code: 'SCR-02', live: false }];

        expect(activePickerOptions(duplicated, { ...spec, keep: 2 })).toHaveLength(3);
    });
});

describe('retiredOptionLabel', () => {
    it('keeps the row own words and only adds the marker', () => {
        expect(retiredOptionLabel('SCR-02 — Short shot')).toBe(`SCR-02 — Short shot ${RETIRED_OPTION_MARKER}`);
    });

    it('says Retired — the one word this product uses for the state', () => {
        expect(RETIRED_OPTION_MARKER).toContain('Retired');
    });
});
