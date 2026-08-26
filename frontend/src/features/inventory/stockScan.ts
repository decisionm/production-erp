import type { Batch, Item, SerialNumber, SerialNumberStatus } from './types';

/**
 * WHAT A BARCODE READ ON THE STOCK PAGE ACTUALLY IS.
 *
 * Pure and separate from the page for one reason: the lookup it does used to
 * be a `.find()` over the DEFAULT FIRST PAGE of the batch and serial lists.
 * Twenty rows out of however many the factory holds, so scanning a batch that
 * was not among the newest twenty answered "no item, batch, or serial number
 * matches" for a barcode printed by this very system — the 12-Aug-2026 picker
 * defect (see src/lib/pickerFullList.test.ts) wearing a scanner.
 *
 * The lookups are now SERVER searches, injected here so this stays testable
 * without a network. Order is most-specific first and it is load-bearing: a
 * serial or a batch match also tells us the item, but a bare SKU never tells
 * us which batch or serial.
 *
 * A serial number only counts as a match while it is in the status the action
 * can actually use — `registered` is receivable, `in_stock` is
 * issuable/transferable — which is the same rule the pickers follow. A
 * scanned unit in the WRONG state is reported as that, not as "no match":
 * the difference between "this barcode is unknown" and "this one is already
 * issued" is the whole answer the person holding the box needs.
 */
export type StockScanKind = 'serial' | 'batch' | 'item' | 'wrong-state' | 'none';

export interface StockScanResult {
    kind: StockScanKind;
    /**
     * What to set on the form. A result that names an item names the WHOLE
     * selection: every key is present, and an identity this scan did not match
     * is explicitly `undefined` so the caller CLEARS it. Anything left behind
     * belonged to the item that was selected before, and posting it against
     * the new one is the cross-item tag the server refuses. Only a no-match
     * leaves the form untouched, by carrying no keys at all.
     */
    fill: { item_id?: number; batch_id?: number; serial_number_id?: number };
    /** One line for the toast. Never a paragraph. */
    message: string;
    ok: boolean;
}

export interface StockScanLookups {
    findSerials: (code: string) => Promise<SerialNumber[]>;
    findBatches: (code: string) => Promise<Batch[]>;
    findItems: (code: string) => Promise<Item[]>;
}

const matches = (candidate: string, code: string) => candidate.trim().toLowerCase() === code;

export async function resolveStockScan(
    rawCode: string,
    usableStatus: SerialNumberStatus,
    lookups: StockScanLookups,
): Promise<StockScanResult> {
    const code = rawCode.trim().toLowerCase();

    if (code === '') {
        return { kind: 'none', fill: {}, message: 'Nothing was scanned.', ok: false };
    }

    const serials = (await lookups.findSerials(rawCode)).filter((s) => matches(s.serial_number, code));
    const usable = serials.find((s) => s.status === usableStatus);
    if (usable) {
        return {
            kind: 'serial',
            fill: { item_id: usable.item.id, batch_id: undefined, serial_number_id: usable.id },
            message: `Matched serial ${usable.serial_number} — ${usable.item.sku}`,
            ok: true,
        };
    }
    if (serials.length > 0) {
        // It IS a serial number of ours, just not one this action can use.
        return {
            kind: 'wrong-state',
            fill: { item_id: serials[0].item.id, batch_id: undefined, serial_number_id: undefined },
            message: `Serial ${serials[0].serial_number} is ${serials[0].status}, not ${usableStatus.replace('_', ' ')}`,
            ok: false,
        };
    }

    const batch = (await lookups.findBatches(rawCode)).find((b) => matches(b.batch_number, code));
    if (batch) {
        return {
            kind: 'batch',
            fill: { item_id: batch.item.id, batch_id: batch.id, serial_number_id: undefined },
            message: `Matched batch ${batch.batch_number} — ${batch.item.sku}`,
            ok: true,
        };
    }

    const item = (await lookups.findItems(rawCode)).find((i) => matches(i.sku, code));
    if (item) {
        return {
            kind: 'item',
            fill: { item_id: item.id, batch_id: undefined, serial_number_id: undefined },
            message: `Matched item ${item.sku}`,
            ok: true,
        };
    }

    return {
        kind: 'none',
        fill: {},
        message: `No item, batch, or serial number matches "${rawCode}"`,
        ok: false,
    };
}
