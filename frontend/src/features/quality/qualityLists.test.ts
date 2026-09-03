import { describe, expect, it } from 'vitest';
import { compactParams, readListParams } from '@/lib/listParams';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import {
    CAPA_LIST,
    INSPECTION_LIST,
    INSTRUMENT_LIST,
    NCR_LIST,
    PRODUCTION_QC_LIST,
    SPC_LIST,
    type QualityList,
    instrumentListRequest,
    instrumentsDueOnly,
} from './qualityLists';

/**
 * The six quality registers read their sort off the URL through one
 * contract: an unknown sort is dropped before it can reach a 422, a known
 * one reaches the server's filters verbatim, and with none the header
 * arrow shows exactly the order the service defaults to.
 */
const LISTS: [string, QualityList, string][] = [
    ['production qc queue', PRODUCTION_QC_LIST, 'quantity_produced'],
    ['incoming inspections', INSPECTION_LIST, 'rejected_quantity'],
    ['ncrs', NCR_LIST, 'raised_date'],
    ['capas', CAPA_LIST, 'due_date'],
    ['instruments', INSTRUMENT_LIST, 'next_calibration_due'],
    ['spc characteristics', SPC_LIST, 'target_value'],
];

describe.each(LISTS)('%s', (_name, list, column) => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), list.spec);

        expect(params.sort).toBeUndefined();
        expect(compactParams(params)).toEqual({});
    });

    it('carries a known sort, either way round, to the server filters', () => {
        expect(compactParams(readListParams(new URLSearchParams(`sort=${column}`), list.spec)).sort).toBe(column);
        expect(compactParams(readListParams(new URLSearchParams(`sort=-${column}`), list.spec)).sort).toBe(`-${column}`);
    });

    it('offers every server column in both directions, and nothing else', () => {
        const allowed = list.spec.allowed?.sort ?? [];
        for (const field of list.sortFields) {
            expect(allowed).toContain(field);
            expect(allowed).toContain(`-${field}`);
        }
        expect(allowed).toHaveLength(list.sortFields.length * 2);
    });

    it('shows the service default on the header when the URL says nothing, and leaves it off the URL', () => {
        const field = list.defaultSort.replace(/^-/, '');
        const direction = list.defaultSort.startsWith('-') ? 'descend' : 'ascend';

        expect(columnSortOrder(field, undefined, list.defaultSort)).toBe(direction);
        // Clicking back to the default order writes nothing: the bare path IS the default.
        expect(sortParamFromSorter({ columnKey: field, order: direction }, [...list.sortFields, 'id'], list.defaultSort)).toBeUndefined();
    });

    it('turns a header click into the server spelling and refuses a column the server lacks', () => {
        expect(sortParamFromSorter({ columnKey: column, order: 'descend' }, list.sortFields, list.defaultSort)).toBe(`-${column}`);
        expect(sortParamFromSorter({ columnKey: 'owner', order: 'ascend' }, list.sortFields, list.defaultSort)).toBeUndefined();
    });
});

describe('the lists keep the filters they already had on the URL', () => {
    it('incoming inspections: a result the enum knows, never one it does not', () => {
        expect(readListParams(new URLSearchParams('result=fail&sort=-result'), INSPECTION_LIST.spec)).toEqual({ result: 'fail', sort: '-result' });
        expect(readListParams(new URLSearchParams('result=maybe'), INSPECTION_LIST.spec)).toEqual({});
    });

    it('instruments: due=1 becomes the server\'s due: 1, and anything else is no filter', () => {
        const on = readListParams(new URLSearchParams('due=1&sort=-code&page=2'), INSTRUMENT_LIST.spec);
        expect(instrumentsDueOnly(on)).toBe(true);
        expect(instrumentListRequest(on)).toEqual({ due: 1, sort: '-code', page: 2 });

        const off = readListParams(new URLSearchParams('due=yes'), INSTRUMENT_LIST.spec);
        expect(instrumentsDueOnly(off)).toBe(false);
        expect(instrumentListRequest(off)).toEqual({});
    });

    it('spc characteristics: item_id is a positive integer or nothing', () => {
        expect(readListParams(new URLSearchParams('item_id=7&sort=target_value'), SPC_LIST.spec)).toEqual({ item_id: 7, sort: 'target_value' });
        expect(readListParams(new URLSearchParams('item_id=abc'), SPC_LIST.spec)).toEqual({});
    });

    it('the queue admits only q, page, per_page and sort — its membership is the server\'s', () => {
        expect(readListParams(new URLSearchParams('q=amber&status=pending&sort=-batch_number&per_page=50'), PRODUCTION_QC_LIST.spec)).toEqual({
            q: 'amber',
            sort: '-batch_number',
            per_page: 50,
        });
    });
});
