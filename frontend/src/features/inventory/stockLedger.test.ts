import { describe, expect, it } from 'vitest';
import {
    movementPurposeLabel,
    PURPOSE_TEXT,
    PURPOSE_TONE,
    movementTypeTone,
    purchaseOrderIdIn,
    stockLedgerParams,
} from './stockLedger';

describe('what the ledger asks the server for', () => {
    it('sends nothing at all when nothing is chosen', () => {
        expect(stockLedgerParams({})).toEqual({});
    });

    it('drops a cleared filter instead of sending an empty one', () => {
        expect(stockLedgerParams({ itemId: null, warehouseId: null })).toEqual({});
    });

    it('sends both ids the endpoint honours', () => {
        expect(stockLedgerParams({ itemId: 7, warehouseId: 3 })).toEqual({ item_id: 7, warehouse_id: 3 });
    });

    it('omits page 1 and sends every page after it', () => {
        expect(stockLedgerParams({ page: 1 })).toEqual({});
        expect(stockLedgerParams({ page: 4 })).toEqual({ page: 4 });
    });

    /**
     * The guard on the filters this endpoint does NOT have. A type or date
     * control over a server-paged list filters the page on screen and hides
     * every match on every other page — so nothing of the sort may leave here
     * until StockMovementController::index reads it.
     */
    it('sends no key the movements endpoint cannot read', () => {
        const params = stockLedgerParams({ itemId: 7, warehouseId: 3, page: 2 });

        expect(Object.keys(params).sort()).toEqual(['item_id', 'page', 'warehouse_id']);
    });
});

describe('the movement type', () => {
    it.each([
        ['receipt', 'green'],
        ['issue', 'red'],
        ['transfer_in', 'blue'],
        ['transfer_out', 'orange'],
    ])('tones %s', (type, tone) => {
        expect(movementTypeTone(type)).toBe(tone);
    });

    it('falls back rather than rendering a colourless tag for a type it has not met', () => {
        expect(movementTypeTone('write_off')).toBe('default');
    });
});

describe('the movement purpose', () => {
    /**
     * The distinction StockMovementPurpose's own docblock insists on:
     * `unknown` is a REAL value — the writer did not say — while a null purpose
     * is a row the backfill has not reached. One is named, the other is a dash.
     */
    it('names a stated-but-empty purpose rather than dashing it', () => {
        expect(movementPurposeLabel('unknown')).toEqual({ text: 'Not stated', tone: 'default' });
    });

    it('has no label at all for a row that carries no purpose', () => {
        expect(movementPurposeLabel(null)).toBeNull();
        expect(movementPurposeLabel(undefined)).toBeNull();
        expect(movementPurposeLabel('')).toBeNull();
    });

    it.each([
        ['opening', 'Opening balance'],
        ['receipt', 'Receipt'],
        ['issue_to_production', 'Issued to production'],
        ['return_from_production', 'Returned from production'],
        ['consumption', 'Consumption'],
        ['output', 'Output'],
        ['dispatch', 'Dispatch'],
        ['adjustment', 'Adjustment'],
        ['scrap', 'Scrap'],
        ['reconcile', 'Reconcile'],
    ])('reads %s as "%s"', (purpose, text) => {
        expect(movementPurposeLabel(purpose)?.text).toBe(text);
    });

    it('never loses a purpose a newer backend added', () => {
        expect(movementPurposeLabel('scrap_write_off')?.text).toBe('scrap write off');
    });
});

describe('the reference', () => {
    it('finds the purchase order a goods receipt names', () => {
        expect(purchaseOrderIdIn('GRN for PO #4')).toBe('4');
        expect(purchaseOrderIdIn('our own note, GRN for PO #128 received')).toBe('128');
    });

    it('finds nothing in a reference that names no order', () => {
        expect(purchaseOrderIdIn('Delivery #12')).toBeNull();
        expect(purchaseOrderIdIn('WO #3')).toBeNull();
        expect(purchaseOrderIdIn(null)).toBeNull();
        expect(purchaseOrderIdIn('')).toBeNull();
    });

    it('does not read a bare number as an order', () => {
        expect(purchaseOrderIdIn('PO 4')).toBeNull();
        expect(purchaseOrderIdIn('SPO #4')).toBeNull();
    });

    /**
     * EVERY PURPOSE THE SERVER CAN SEND HAS AN EXPLICIT ENTRY HERE.
     *
     * ASSERTED ON THE MAPS, NOT ON THE RENDERED LABEL, and the difference is
     * the whole test. `movementPurposeLabel` falls back to
     * `purpose.replaceAll('_', ' ')`, so an unmapped purpose renders as
     * "quality release" in the default grey — readable enough that a test on
     * the OUTPUT passes while the entry is missing. (It did: the first version
     * of this test asserted the text was not the raw enum value and went green
     * against a map that had never heard of `quality_release`.) What is
     * actually lost is the factory's own word for the act and its tone, so the
     * keys are what get checked.
     *
     * The fallback stays — an ERP served by a newer backend must not lose an
     * answer it was given — this pins that we do not RELY on it.
     *
     * Kept as a literal list rather than derived from the maps, so adding a
     * purpose to a map cannot silently satisfy its own test.
     */
    it('has an explicit word and tone for every purpose the server sends', () => {
        const serverPurposes = [
            'opening', 'receipt', 'issue_to_production', 'return_from_production',
            'consumption', 'output', 'dispatch', 'adjustment', 'scrap',
            'quality_release', 'reconcile', 'unknown',
        ];

        for (const purpose of serverPurposes) {
            expect(PURPOSE_TEXT, `no word for "${purpose}"`).toHaveProperty(purpose);
            expect(PURPOSE_TONE, `no tone for "${purpose}"`).toHaveProperty(purpose);
        }
    });
});
