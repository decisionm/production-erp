import { describe, expect, it } from 'vitest';
import { isMassUom } from './pages/GoodsReceiptsPage';

/**
 * THE UNIT TEST THAT KEEPS TWO CODEBASES AGREEING.
 *
 * Since 31-Aug-2026 (Q77) the backend REQUIRES a lots block on a weighed
 * arrival, and refuses one outright on a counted arrival. This function is
 * what decides whether the receiving form pre-opens the row to enter it. If
 * it and the backend's Item::isKgUom ever disagree about a unit, the
 * storekeeper is either shown no row and handed a refusal they cannot
 * satisfy, or shown a row the server will reject.
 *
 * The cases below are the backend's own normalisation, restated: lowercase,
 * trim, and strip EVERY trailing dot — Tally writes "Kgs." on 90+ live items.
 */
describe('isMassUom mirrors the backend Item::isKgUom', () => {
    it.each(['kg', 'Kg', 'KGS', 'kgs', 'Kilogram', 'kilograms', ' kg ', 'Kgs.', 'Kgs..'])(
        '%s is the kg family',
        (uom) => expect(isMassUom(uom)).toBe(true),
    );

    it.each(['Nos', 'nos', 'Mtr', 'Litre', 'pcs', '', 'kgx', 'kilo'])(
        '%s is not',
        (uom) => expect(isMassUom(uom)).toBe(false),
    );
});
