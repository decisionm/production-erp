import { describe, expect, it } from 'vitest';
import { REMINDER_NOT_CONNECTED, reminderNotConnectedMessage } from '@/features/finance/clientReminder';

describe('reminderNotConnectedMessage', () => {
    it('says nothing was sent, and to whom', () => {
        expect(reminderNotConnectedMessage('Northwind Traders'))
            .toBe('Reminders are not connected yet — nothing was sent to Northwind Traders.');
    });

    it('still says nothing was sent when the client has no usable name', () => {
        // A Tally ledger with no linked customer can render blank; the
        // sentence must never degrade into something that reads like a send.
        expect(reminderNotConnectedMessage('   ')).toBe('Reminders are not connected yet — nothing was sent.');
        expect(reminderNotConnectedMessage('')).toContain('nothing was sent');
    });

    it('leads with the fact that it is not connected', () => {
        // Toasts are read from the front and are gone in seconds — "not
        // connected" has to be the first thing, not a clause at the end.
        expect(reminderNotConnectedMessage('Anyone').startsWith(REMINDER_NOT_CONNECTED)).toBe(true);
    });
});
