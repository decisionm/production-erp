import { describe, expect, it } from 'vitest';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import {
    MATERIAL_BAG_DEFAULT_SORT,
    MATERIAL_BAG_SORT_FIELDS,
    MATERIAL_LOT_SORT_FIELDS,
    materialLotDefaultSort,
} from './traceabilityRegisters';

describe('the lot register', () => {
    it('follows the newest/oldest switch until a header is clicked', () => {
        expect(materialLotDefaultSort('newest')).toBe('-received_date');
        expect(materialLotDefaultSort('oldest')).toBe('received_date');
        expect(columnSortOrder('received_date', undefined, materialLotDefaultSort('oldest'))).toBe('ascend');
        expect(columnSortOrder('bag_count', undefined, materialLotDefaultSort('oldest'))).toBeNull();
    });

    it('drops a column the server cannot order by, and leaves the switch\'s own order off the request', () => {
        const defaultSort = materialLotDefaultSort('newest');

        expect(sortParamFromSorter({ columnKey: 'receipt_price', order: 'descend' }, MATERIAL_LOT_SORT_FIELDS, defaultSort)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'received_date', order: 'descend' }, MATERIAL_LOT_SORT_FIELDS, defaultSort)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'received_date', order: 'ascend' }, MATERIAL_LOT_SORT_FIELDS, defaultSort)).toBe('received_date');
        expect(sortParamFromSorter({ columnKey: 'total_received_kg', order: 'descend' }, MATERIAL_LOT_SORT_FIELDS, defaultSort)).toBe('-total_received_kg');
    });
});

describe('the bag register', () => {
    it('opens oldest bag first, and leaves that off the request', () => {
        expect(MATERIAL_BAG_DEFAULT_SORT).toBe('id');
        expect(sortParamFromSorter({ columnKey: 'id', order: 'ascend' }, MATERIAL_BAG_SORT_FIELDS, MATERIAL_BAG_DEFAULT_SORT)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'remaining_kg', order: 'descend' }, MATERIAL_BAG_SORT_FIELDS, MATERIAL_BAG_DEFAULT_SORT)).toBe('-remaining_kg');
        expect(sortParamFromSorter({ columnKey: 'supplier_lot', order: 'ascend' }, MATERIAL_BAG_SORT_FIELDS, MATERIAL_BAG_DEFAULT_SORT)).toBeUndefined();
    });
});
