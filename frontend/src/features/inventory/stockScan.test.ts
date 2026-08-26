import { beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/lib/api';
import { resolveStockScan, serverScanLookups, type StockScanLookups } from './stockScan';
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

/**
 * THE ADVERSARIAL CASE: A CODE HIDDEN BEHIND ITS OWN DECOYS.
 *
 * The scan used to ask the server `?search=<code>&per_page=50`. `LOT-4` is a
 * substring of `LOT-4001` … `LOT-4060`, every one of them newer, and the list
 * comes back newest first — so fifty decoys filled the reply and the real row
 * was never in it. The floor sees "no item, batch, or serial number matches"
 * for a barcode this system printed, with the stock standing right there.
 *
 * The fake server below implements BOTH semantics honestly: `search` is a
 * substring match truncated at `per_page`, `code` is a whole-value match. The
 * first assertion of each test shows the old wiring still fails against it —
 * so this is not a test that passes because the fake is friendly.
 */
vi.mock('@/lib/api', () => ({ api: { get: vi.fn() } }));

const DECOYS = 60;

const decoyBatches = Array.from({ length: DECOYS }, (_, i) =>
    batch(1000 + i, `LOT-4${String(i + 1).padStart(3, '0')}`));
const realBatch = batch(1, 'LOT-4');

const decoySerials = Array.from({ length: DECOYS }, (_, i) =>
    serial(2000 + i, `SN-7${String(i + 1).padStart(3, '0')}`, 'in_stock'));
const realSerial = serial(2, 'SN-7', 'in_stock');

/**
 * Newest first — `orderByDesc('id')`, exactly as the server orders these
 * lists. The real row is the OLDEST, which is the whole shape of the defect:
 * the code was printed before the sixty that contain it.
 */
const rowsFor = (url: string) =>
    (url.includes('batches') ? [realBatch, ...decoyBatches] : [realSerial, ...decoySerials])
        .slice()
        .sort((a, b) => b.id - a.id);

const numberOf = (row: unknown) =>
    (row as { batch_number?: string; serial_number?: string }).batch_number
    ?? (row as { serial_number: string }).serial_number;

const fakeServer = (url: string, config?: { params?: Record<string, unknown> }) => {
    const rows = rowsFor(url);
    const { code, search, per_page: perPage = 20 } = config?.params ?? {};

    const matched = code !== undefined && code !== ''
        ? rows.filter((row) => numberOf(row).toLowerCase() === String(code).toLowerCase())
        : search !== undefined && search !== ''
            ? rows.filter((row) => numberOf(row).toLowerCase().includes(String(search).toLowerCase()))
            : rows;

    // THE CAP IS THE DEFECT. A page is a page whichever parameter narrowed it.
    return Promise.resolve({ data: { data: matched.slice(0, Number(perPage)), meta: { total: matched.length } } });
};

describe('a scan resolves the whole identifier on the server', () => {
    beforeEach(() => {
        vi.mocked(api.get).mockReset();
        vi.mocked(api.get).mockImplementation(fakeServer as never);
    });

    it('finds a batch that sixty substring decoys would have hidden', async () => {
        // The OLD wiring, against the same server: still broken.
        const old = await resolveStockScan('LOT-4', 'in_stock', lookups({
            findBatches: async (code) => (await fakeServer('/inventory/batches', {
                params: { search: code, per_page: 50 },
            })).data.data as never,
        }));
        expect(old.kind, 'a capped substring search reached past its page').toBe('none');

        const result = await resolveStockScan('LOT-4', 'in_stock', serverScanLookups([]));

        expect(result.kind).toBe('batch');
        expect(result.fill.batch_id).toBe(realBatch.id);
        expect(result.ok).toBe(true);
    });

    it('finds a serial number that sixty substring decoys would have hidden', async () => {
        const old = await resolveStockScan('SN-7', 'in_stock', lookups({
            findSerials: async (code) => (await fakeServer('/inventory/serial-numbers', {
                params: { search: code, per_page: 50 },
            })).data.data as never,
        }));
        expect(old.kind, 'a capped substring search reached past its page').toBe('none');

        const result = await resolveStockScan('SN-7', 'in_stock', serverScanLookups([]));

        expect(result.kind).toBe('serial');
        expect(result.fill.serial_number_id).toBe(realSerial.id);
    });

    it('asks the server to resolve the code, never to search for it', async () => {
        // A green test whose lookup still sent `search` would prove nothing.
        await resolveStockScan('LOT-4', 'in_stock', serverScanLookups([]));

        for (const [, config] of vi.mocked(api.get).mock.calls) {
            const params = (config as { params?: Record<string, unknown> } | undefined)?.params ?? {};
            expect(params.code).toBe('LOT-4');
            expect(params.search).toBeUndefined();
        }
    });

    it('still answers "no match" for a code that is nobody\'s number', async () => {
        const result = await resolveStockScan('LOT-9999', 'in_stock', serverScanLookups([]));

        expect(result.kind).toBe('none');
        expect(result.fill).toEqual({});
    });
});
