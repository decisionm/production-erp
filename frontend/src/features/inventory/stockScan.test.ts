import { describe, expect, it, vi } from 'vitest';
import { resolveStockScan, type StockScanLookups } from './stockScan';
import type { Batch, Item, SerialNumber, SerialNumberStatus } from './types';

const item = (id: number, sku: string) => ({ id, sku, name: sku, uom: 'Nos' }) as unknown as Item;

const serial = (id: number, number: string, status: SerialNumberStatus, itemId = 1) =>
    ({ id, serial_number: number, status, item: item(itemId, `SKU-${itemId}`) }) as unknown as SerialNumber;

const batch = (id: number, number: string, itemId = 2) =>
    ({ id, batch_number: number, item: item(itemId, `SKU-${itemId}`) }) as unknown as Batch;

const lookups = (parts: Partial<StockScanLookups> = {}): StockScanLookups => ({
    findSerials: async () => [],
    findBatches: async () => [],
    findItems: async () => [],
    ...parts,
});

describe('resolveStockScan', () => {
    it('matches a serial number in the status the action can use', async () => {
        const result = await resolveStockScan('SN-9', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'in_stock')],
        }));

        expect(result.kind).toBe('serial');
        expect(result.fill).toEqual({ item_id: 1, serial_number_id: 7 });
        expect(result.ok).toBe(true);
    });

    it('says a scanned serial number is in the wrong state rather than "no match"', async () => {
        // The physical difference the person holding the box needs: this
        // barcode IS ours, it has already been issued.
        const result = await resolveStockScan('SN-9', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'consumed')],
        }));

        expect(result.kind).toBe('wrong-state');
        expect(result.ok).toBe(false);
        expect(result.message).toContain('consumed');
        // The item is still filled in — it is known and it is right.
        expect(result.fill).toEqual({ item_id: 1 });
        expect(result.fill.serial_number_id).toBeUndefined();
    });

    it('reads a serial number that a receipt can use but an issue cannot', async () => {
        const registered = lookups({ findSerials: async () => [serial(7, 'SN-9', 'registered')] });

        expect((await resolveStockScan('SN-9', 'registered', registered)).kind).toBe('serial');
        expect((await resolveStockScan('SN-9', 'in_stock', registered)).kind).toBe('wrong-state');
    });

    it('falls through to a batch, then to a bare SKU', async () => {
        const byBatch = await resolveStockScan('LOT-4', 'in_stock', lookups({
            findBatches: async () => [batch(3, 'LOT-4')],
        }));
        expect(byBatch.fill).toEqual({ item_id: 2, batch_id: 3 });

        const bySku = await resolveStockScan('SKU-5', 'in_stock', lookups({
            findItems: async () => [item(5, 'SKU-5')],
        }));
        expect(bySku.fill).toEqual({ item_id: 5 });
        expect(bySku.fill.batch_id).toBeUndefined();
    });

    it('takes the serial number over a batch that shares the code', async () => {
        // Most-specific first: a serial match tells us the batch is a
        // coincidence, never the other way round.
        const result = await resolveStockScan('DUP', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'DUP', 'in_stock')],
            findBatches: async () => [batch(3, 'DUP')],
        }));

        expect(result.kind).toBe('serial');
    });

    it('ignores a near miss the server returned — the match must be exact', async () => {
        // `search` is a LIKE, so "SN-1" comes back with SN-10 and SN-100
        // beside it. Filling the form from a prefix would issue the wrong
        // physical unit.
        const result = await resolveStockScan('SN-1', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-10', 'in_stock'), serial(8, 'SN-100', 'in_stock')],
            findBatches: async () => [batch(3, 'SN-1000')],
        }));

        expect(result.kind).toBe('none');
        expect(result.fill).toEqual({});
    });

    it('matches regardless of case and surrounding whitespace', async () => {
        const result = await resolveStockScan('  sn-9  ', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'in_stock')],
        }));

        expect(result.kind).toBe('serial');
    });

    it('does not ask the server anything for an empty scan', async () => {
        const findSerials = vi.fn(async () => []);

        const result = await resolveStockScan('   ', 'in_stock', lookups({ findSerials }));

        expect(result.kind).toBe('none');
        expect(findSerials).not.toHaveBeenCalled();
    });

    it('stops looking once it has matched — a serial scan costs one query', async () => {
        const findBatches = vi.fn(async () => []);
        const findItems = vi.fn(async () => []);

        await resolveStockScan('SN-9', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'in_stock')],
            findBatches,
            findItems,
        }));

        expect(findBatches).not.toHaveBeenCalled();
        expect(findItems).not.toHaveBeenCalled();
    });

    /*
     * A SCAN NAMES THE WHOLE SELECTION.
     *
     * The modal keeps one set of form values across scans, so an identity left
     * over from the item chosen a moment ago is still sitting there when the
     * next barcode lands. Posting it against the NEW item is precisely the
     * cross-item tag the server refuses (ValidatesTrackingIdentity) — a 422 on
     * a picker the person never touched. Every matching result therefore
     * carries both identity keys, `undefined` where it matched nothing, and
     * the caller clears what it is handed.
     */
    it('clears a stale batch when the next scan is a bare SKU', async () => {
        const result = await resolveStockScan('SKU-4', 'in_stock', lookups({
            findItems: async () => [item(4, 'SKU-4')],
        }));

        expect(result.kind).toBe('item');
        expect(result.fill).toStrictEqual({ item_id: 4, batch_id: undefined, serial_number_id: undefined });
    });

    it('clears a stale serial number when the scan matches a batch', async () => {
        const result = await resolveStockScan('B-1', 'in_stock', lookups({
            findBatches: async () => [batch(3, 'B-1')],
        }));

        expect(result.fill).toStrictEqual({ item_id: 2, batch_id: 3, serial_number_id: undefined });
    });

    it('clears a stale batch when the scan matches a serial number', async () => {
        const result = await resolveStockScan('SN-9', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'in_stock')],
        }));

        expect(result.fill).toStrictEqual({ item_id: 1, batch_id: undefined, serial_number_id: 7 });
    });

    it('clears both identities when the unit is real but in the wrong state', async () => {
        const result = await resolveStockScan('SN-9', 'in_stock', lookups({
            findSerials: async () => [serial(7, 'SN-9', 'consumed')],
        }));

        expect(result.kind).toBe('wrong-state');
        expect(result.fill).toStrictEqual({ item_id: 1, batch_id: undefined, serial_number_id: undefined });
    });

    it('leaves the form alone entirely when nothing matches', async () => {
        const result = await resolveStockScan('NOPE', 'in_stock', lookups());

        expect(result.kind).toBe('none');
        expect(Object.keys(result.fill)).toHaveLength(0);
    });
});
