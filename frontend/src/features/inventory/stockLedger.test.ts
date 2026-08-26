import { describe, expect, it } from 'vitest';
import {
    movementPurposeLabel,
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
});
