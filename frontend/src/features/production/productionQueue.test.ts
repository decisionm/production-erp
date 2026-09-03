import { describe, expect, it } from 'vitest';
import { queueRowActionsAllowed } from './productionQueue';

describe('queueRowActionsAllowed', () => {
    it('allows a queued row', () => {
        expect(queueRowActionsAllowed('queued')).toBe(true);
    });

    it('allows an in-progress row', () => {
        expect(queueRowActionsAllowed('in_progress')).toBe(true);
    });

    it('refuses a produced row — it retired on its own, nothing puts it back', () => {
        expect(queueRowActionsAllowed('produced')).toBe(false);
    });

    it('refuses a cancelled row', () => {
        expect(queueRowActionsAllowed('cancelled')).toBe(false);
    });
});
