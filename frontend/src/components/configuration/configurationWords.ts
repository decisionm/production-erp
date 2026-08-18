/**
 * THE WORDS. Every state, every act and every blocking reason a configuration
 * master can show, decided in ONE pure module and nowhere else.
 *
 * The audit found ~20 hand-rolled status tags and five ad-hoc confirm dialogs
 * across 70+ pages: "In service" here, a bare disabled Switch there, "Retired"
 * on a third screen for the same fact. One vocabulary is the fix — and putting
 * it in a pure module (no React, no antd) means it is unit-tested, while the
 * components below stay thin renderers of it.
 *
 * Two rules this module exists to keep:
 *
 *  1. **Eligibility is the server's.** Nothing here inspects a record to work
 *     out whether it may be deleted; it reads the `can` block and turns it
 *     into words. A refused delete gets a GENERIC reason, because the `can`
 *     block carries no cause — naming one would be a guess, and the
 *     authoritative report only exists once the delete has been attempted.
 *  2. **The server owns the prose.** Its refusal sentence is printed verbatim.
 *     The sentence built from counts here is a fallback for a payload that
 *     arrives without one, not a second version of the same message.
 */

import type {
    BlockingReason,
    ConfigurationAbilities,
    ConfigurationAction,
    ConfigurationActionKey,
    ConfigurationInUse,
} from './types';

// ---------------------------------------------------------------------------
// The state vocabulary — exactly two words, product-wide
// ---------------------------------------------------------------------------

/**
 * Active or Retired. Not "In service", not "Inactive", not a switch that is
 * merely off: a supervisor reading a master must be told whether the row is
 * gone or just paused, in the same two words on all 26 screens.
 *
 * The ACT that produces the retired state is called "Archive" (it is
 * reversible and takes a reason); the STATE it produces is "Retired". Those
 * are the only two words for it.
 */
export interface StatusWords {
    label: 'Active' | 'Retired';
    /** antd Tag tone. Colour is never the only signal — the word is always shown. */
    tone: 'success' | 'default';
    description: string;
}

export const CONFIGURATION_STATUS_WORDS: Record<'active' | 'retired', StatusWords> = {
    active: {
        label: 'Active',
        tone: 'success',
        description: 'In service — offered wherever this record can be picked.',
    },
    retired: {
        label: 'Retired',
        tone: 'default',
        description: 'Retired — kept for its history and not offered for new work.',
    },
};

export const statusWords = (active: boolean): StatusWords =>
    active ? CONFIGURATION_STATUS_WORDS.active : CONFIGURATION_STATUS_WORDS.retired;

// ---------------------------------------------------------------------------
// The blocking reasons
// ---------------------------------------------------------------------------

/**
 * One line of the blocking list: the count in front of the server's own label,
 * built exactly the way the backend builds its own sentence. The plural word
 * is the server's — inventing a singular of "production batches" here would be
 * this UI making up a word for a factory thing it does not own.
 *
 * A reason with no count is the fail-closed verdict (historical use that could
 * not be proven, DEC-20260817-002): it is stated as-is and it blocks the same.
 */
export const blockingLine = (reason: BlockingReason): string =>
    reason.count === null ? reason.label : `${reason.count} ${reason.label}`;

/** "12 stock movements, 2 production batches and 1 configurations". */
export function blockingSentence(blocking: BlockingReason[]): string {
    const lines = blocking.map(blockingLine);
    if (lines.length === 0) return '';
    if (lines.length === 1) return lines[0];
    return `${lines.slice(0, -1).join(', ')} and ${lines[lines.length - 1]}`;
}

// ---------------------------------------------------------------------------
// The 422 discriminator
// ---------------------------------------------------------------------------

/** The response body of an axios rejection, without assuming its shape. */
const responseBody = (error: unknown): Record<string, unknown> => {
    const data = (error as { response?: { data?: unknown } } | undefined)?.response?.data;
    return data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : {};
};

/** Keeps a reason only when it carries the server's words; a countless one survives. */
function normaliseBlocking(raw: unknown): BlockingReason[] {
    if (!Array.isArray(raw)) return [];
    const out: BlockingReason[] = [];
    for (const entry of raw) {
        if (entry === null || typeof entry !== 'object') continue;
        const row = entry as Record<string, unknown>;
        if (typeof row.label !== 'string' || row.label === '') continue;
        const count = Number(row.count);
        out.push({
            code: typeof row.code === 'string' ? row.code : '',
            label: row.label,
            // NaN/absent → null, never 0: "0 stock movements" would read as
            // "nothing uses it", which is the opposite of a refusal.
            count: row.count === null || row.count === undefined || !Number.isFinite(count) ? null : Math.trunc(count),
        });
    }
    return out;
}

/**
 * A cascade gap is the THIRD refusal list, and it is not the reader's fault:
 * the schema says this table cascades from a column the module's declaration
 * never accounted for, so the mechanism refuses rather than destroy a child it
 * cannot see. It is shown like any other reason — a refusal is a refusal to the
 * person holding the mouse — but it carries the server's own message, which
 * names the table and column so an engineer can close the gap.
 */
function normaliseCascadeGaps(raw: unknown): BlockingReason[] {
    if (!Array.isArray(raw)) return [];
    const out: BlockingReason[] = [];
    for (const entry of raw) {
        if (entry === null || typeof entry !== 'object') continue;
        const row = entry as Record<string, unknown>;
        const table = typeof row.table === 'string' ? row.table : '';
        const column = typeof row.column === 'string' ? row.column : '';
        const message = typeof row.message === 'string' && row.message !== '' ? row.message : '';
        const label = message !== '' ? message : (table !== '' ? `an unchecked link from ${table}` : '');
        if (label === '') continue;
        out.push({
            code: typeof row.reason === 'string' && row.reason !== '' ? row.reason : 'cascade_gap',
            label,
            // Never a number: a gap is "we cannot see this", not "there are N".
            count: null,
        });
    }
    return out;
}

/**
 * Is this failure the contract's in-use refusal? THE discriminator: an in-use
 * 422 must render the blocking list with its counts, and every other failure
 * must go to the shared error modal. Routing an in-use refusal into the
 * generic modal would silently hide the only thing the reader needs.
 */
export function configurationInUse(error: unknown): ConfigurationInUse | null {
    const data = responseBody(error);
    if (data.code !== 'configuration_in_use') return null;
    return {
        message: typeof data.message === 'string' && data.message !== '' ? data.message : null,
        // The server sends TWO lists. `blocking` is what it counted; `unprovable`
        // is what it could not — a legacy record whose past use cannot be shown
        // either way, which blocks the delete exactly as hard (fail-closed,
        // DEC-20260817-002 point 5). To a reader they are one list of reasons, so
        // the uncountable ones join with a null count and `blockingLine` states
        // them without a number. Dropping them would render an EMPTY modal for a
        // refusal that really happened.
        blocking: [
            ...normaliseBlocking(data.blocking),
            ...normaliseBlocking(data.unprovable),
            ...normaliseCascadeGaps(data.cascade_gaps),
        ],
        alternative: typeof data.alternative === 'string' && data.alternative !== '' ? data.alternative : null,
    };
}

/**
 * The headline of the refusal. The server's sentence wins — it names the
 * record and reads "Cannot delete item "X" — used by 12 stock movements and 2
 * production batches. Deactivate instead." Only if it is missing does the UI
 * build one from the counts, and it still never guesses a cause.
 */
export function inUseHeadline(inUse: ConfigurationInUse, entityLabel: string): string {
    if (inUse.message !== null) return inUse.message;
    const sentence = blockingSentence(inUse.blocking);
    return sentence === ''
        ? `Cannot delete this ${entityLabel} — something already uses it.`
        : `Cannot delete this ${entityLabel} — used by ${sentence}.`;
}

// ---------------------------------------------------------------------------
// "Archive instead" — an offer only when it would actually do something
// ---------------------------------------------------------------------------

/**
 * Offered only when the server named archive as the alternative AND says the
 * record may be archived. An "Archive instead" button on an already-retired
 * record is a dead control that reads like the refusal has a way out when it
 * does not — and the server already refuses it: `abilities()` is
 * `!trashed && isActive && …`, so a retired record arrives with
 * `archive: false`.
 *
 * That is why the record's own active flag is NOT read here. Re-deriving
 * eligibility from the row is exactly what this contract exists to stop; the
 * `can` block is the answer, and a UI second opinion could only ever disagree
 * with it.
 */
export const canOfferArchive = (input: {
    alternative: string | null;
    can: ConfigurationAbilities | null | undefined;
}): boolean => input.alternative === 'archive' && input.can?.archive === true;

// ---------------------------------------------------------------------------
// The four acts
// ---------------------------------------------------------------------------

export const CONFIGURATION_ACTION_LABEL: Record<ConfigurationActionKey, string> = {
    edit: 'Edit',
    activate: 'Reactivate',
    archive: 'Archive',
    delete: 'Delete',
};

/** Why an act is not offered. Generic on purpose — the `can` block carries no cause. */
export const CONFIGURATION_ACTION_REFUSED: Record<ConfigurationActionKey, string> = {
    edit: 'Editing is not available for this record.',
    activate: 'Reactivating is not available for this record.',
    archive: 'Archiving is not available for this record.',
    // Deliberately says nothing about WHAT uses it: only the authoritative
    // report (fetched by the confirm, or returned by the refusal) knows, and
    // a tooltip that guessed would be this UI re-deriving eligibility in prose.
    delete: 'Something already uses this record. Archive it instead.',
};

/** `delete: null` on an index row — undetermined, so the confirm goes and asks. */
export const DELETE_UNDETERMINED = 'Not checked yet — confirming asks the server what uses it.';

/**
 * The row's acts, in one order, with the server's verdict attached to each.
 *
 * `available` is the SCREEN's business (a page with no archive flow passes
 * `{archive: false}`); `can` is the SERVER's. The two are never confused: a
 * screen may hide an act it does not implement, but it may never enable one
 * the server disabled.
 */
export function configurationActions(
    can: ConfigurationAbilities | null | undefined,
    available: Partial<Record<ConfigurationActionKey, boolean>> = {},
): ConfigurationAction[] {
    // No `can` block means the server has not said — so nothing is offered.
    // Assuming "allowed" here is exactly the UI-only-disabling failure the
    // contract was written against.
    if (can === null || can === undefined) return [];

    const order: ConfigurationActionKey[] = ['edit', 'activate', 'archive', 'delete'];

    return order
        .filter((key) => available[key] !== false)
        .map((key) => {
            if (key === 'delete') {
                const verdict = can.delete;
                return {
                    key,
                    label: CONFIGURATION_ACTION_LABEL.delete,
                    danger: true,
                    // Undetermined is askable, not allowed: the confirm resolves it.
                    enabled: verdict !== false,
                    reason: verdict === false ? CONFIGURATION_ACTION_REFUSED.delete : verdict === null ? DELETE_UNDETERMINED : null,
                };
            }
            const enabled = can[key];
            return {
                key,
                label: CONFIGURATION_ACTION_LABEL[key],
                danger: false,
                enabled,
                reason: enabled ? null : CONFIGURATION_ACTION_REFUSED[key],
            };
        });
}

// ---------------------------------------------------------------------------
// The delete confirm's own words
// ---------------------------------------------------------------------------

/** `entityLabel` is the page's word for the thing — "mould", "item", "machine". */
export const deleteModalTitle = (entityLabel: string, recordName: string | null): string =>
    recordName === null || recordName === '' ? `Delete this ${entityLabel}?` : `Delete ${entityLabel} “${recordName}”?`;

/**
 * Says the two things the reader must know before pressing: the delete is a
 * real hard delete (the row goes), and archiving is the reversible option.
 */
export const deleteConfirmBody = (entityLabel: string, recordName: string | null): string =>
    `${recordName === null || recordName === '' ? `This ${entityLabel}` : `“${recordName}”`} is permanently removed from the database — this cannot be undone. ` +
    `The server checks every dependency first and refuses if anything has ever used it.`;

/** The reversible way out, offered on a refusal. */
/**
 * The button under a refusal, worded to MATCH the sentence directly above it.
 *
 * The server's refusal ends "Deactivate instead." — the lead's own words in the
 * requirement, and pinned by test. Offering a button labelled "Archive instead"
 * beneath that sentence asks the reader to work out that two words mean one act.
 * So the OFFER says Deactivate; the act's name elsewhere in the API and the row
 * menu is still Archive, and the STATE it produces is still Retired.
 */
export const ARCHIVE_INSTEAD_LABEL = 'Deactivate instead';

/**
 * Archive and Reactivate both ASK for a reason, and today nothing stores it:
 * there is no reason column on any master and `ConfigurationLifecycle` takes
 * the argument and drops it. So the words do not promise otherwise. The
 * earlier wording ("the reason is kept with the record") described a feature
 * that does not exist — a promise a screen must never make on the factory's
 * behalf. If a reason column is added, this is the string that changes.
 */
export const REASON_LABEL = 'Reason';
export const REASON_REQUIRED = 'Say why this is being archived.';
