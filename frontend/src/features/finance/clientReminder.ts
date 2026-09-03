/**
 * WHAT PRESSING "SEND REMINDER" SAYS, WHILE NOTHING CAN YET SEND ONE.
 *
 * The control on Client Outstanding is the finished button in its finished
 * place — primary, pressable, real — because the sending side is being built
 * around it. The single thing it must never do is let somebody believe a
 * client was chased when nobody was: an unchased client whose screen implies
 * otherwise is money going quiet, and nothing later detects it.
 *
 * So the wording lives here, on its own, with a test of its own. Wiring
 * reminders up means deleting this and its test — which is exactly the point.
 * A quiet swap of the handler cannot happen without somebody saying out loud
 * what pressing the button now means.
 */
export const REMINDER_NOT_CONNECTED = 'Reminders are not connected yet';

/** The full sentence, naming the client so it is never mistaken for a send. */
export function reminderNotConnectedMessage(clientName: string): string {
    const named = clientName.trim();

    return named === ''
        ? `${REMINDER_NOT_CONNECTED} — nothing was sent.`
        : `${REMINDER_NOT_CONNECTED} — nothing was sent to ${named}.`;
}
