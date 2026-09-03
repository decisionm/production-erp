import { describe, expect, it } from 'vitest';
import { isDefaultQueueView, queueRowActionsAllowed } from './productionQueue';

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

describe('isDefaultQueueView', () => {
    it('is the default view when nobody has touched the filter', () => {
        expect(isDefaultQueueView(['queued', 'in_progress'])).toBe(true);
    });

    it('is the default view in either order', () => {
        expect(isDefaultQueueView(['in_progress', 'queued'])).toBe(true);
    });

    it('is the default view when the multi-select is cleared to empty — 03-Sep-2026 fix', () => {
        expect(isDefaultQueueView([])).toBe(true);
    });

    it('is a deliberate filter act for a single non-default status, and loses the default view', () => {
        expect(isDefaultQueueView(['produced'])).toBe(false);
    });

    it('is a deliberate filter act for a single default status alone', () => {
        expect(isDefaultQueueView(['queued'])).toBe(false);
    });

    it('is a deliberate filter act when every status is ticked', () => {
        expect(isDefaultQueueView(['queued', 'in_progress', 'produced', 'cancelled'])).toBe(false);
    });
});
