import { describe, expect, it } from 'vitest';
import {
    ARCHIVE_INSTEAD_LABEL,
    REASON_MODAL_TITLE,
    REASON_REQUIRED,
    REASON_REQUIRED_ACTIVATE,
    blockingLine,
    blockingSentence,
    canOfferArchive,
    configurationActions,
    configurationInUse,
    deleteConfirmBody,
    deleteModalTitle,
    inUseHeadline,
    statusWords,
} from './configurationWords';
import type { ConfigurationAbilities } from './types';

const abilities = (overrides: Partial<ConfigurationAbilities> = {}): ConfigurationAbilities => ({
    edit: true,
    activate: false,
    archive: true,
    delete: true,
    ...overrides,
});

const inUseError = (payload: Record<string, unknown>) => ({ response: { status: 422, data: payload } });

// ---------------------------------------------------------------------------
// The state vocabulary — two words, product-wide
// ---------------------------------------------------------------------------

describe('statusWords', () => {
    it('says Active and Retired and nothing else', () => {
        expect(statusWords(true).label).toBe('Active');
        expect(statusWords(false).label).toBe('Retired');
    });

    it('gives the retired state a tone of its own so the two are not read by colour alone', () => {
        expect(statusWords(true).tone).toBe('success');
        expect(statusWords(false).tone).toBe('default');
    });

    it('explains what each state means for picking the record', () => {
        expect(statusWords(true).description).toContain('offered');
        expect(statusWords(false).description).toContain('history');
    });
});

// ---------------------------------------------------------------------------
// The blocking reasons — counts from the server, words from the server
// ---------------------------------------------------------------------------

describe('blockingLine', () => {
    it('states the count in front of the server label, exactly as the backend sentence does', () => {
        expect(blockingLine({ code: 'stock_movements', label: 'stock movements', count: 12 })).toBe('12 stock movements');
    });

    it('never invents a singular of a server-owned word', () => {
        expect(blockingLine({ code: 'batches', label: 'production batches', count: 1 })).toBe('1 production batches');
    });

    it('prints the label alone when a count did not survive the wire', () => {
        expect(blockingLine({ code: 'x', label: 'stock movements', count: null })).toBe('stock movements');
    });
});

describe('blockingSentence', () => {
    it('is empty for nothing', () => {
        expect(blockingSentence([])).toBe('');
    });

    it('joins two with "and" and three with commas', () => {
        expect(
            blockingSentence([
                { code: 'a', label: 'stock movements', count: 12 },
                { code: 'b', label: 'production batches', count: 2 },
            ]),
        ).toBe('12 stock movements and 2 production batches');
        expect(
            blockingSentence([
                { code: 'a', label: 'stock movements', count: 12 },
                { code: 'b', label: 'production batches', count: 2 },
                { code: 'c', label: 'configurations', count: 1 },
            ]),
        ).toBe('12 stock movements, 2 production batches and 1 configurations');
    });
});

// ---------------------------------------------------------------------------
// The 422 discriminator — an in-use refusal must never fall into the generic
// error modal, which is what would hide the blocking list
// ---------------------------------------------------------------------------

describe('configurationInUse', () => {
    it('recognises the refusal by its code and keeps every count as a number', () => {
        const parsed = configurationInUse(
            inUseError({
                message: 'Cannot delete item "X" — used by 12 stock movements and 2 production batches. Deactivate instead.',
                code: 'configuration_in_use',
                blocking: [
                    { code: 'stock_movements', label: 'stock movements', count: 12 },
                    { code: 'batches', label: 'production batches', count: 2 },
                ],
                alternative: 'archive',
            }),
        );

        expect(parsed).not.toBeNull();
        expect(parsed!.blocking).toEqual([
            { code: 'stock_movements', label: 'stock movements', count: 12 },
            { code: 'batches', label: 'production batches', count: 2 },
        ]);
        expect(parsed!.alternative).toBe('archive');
        expect(parsed!.message).toContain('Cannot delete item "X"');
    });

    it('is null for any other error — a validation 422, a domain 422 of another kind, a network failure', () => {
        expect(configurationInUse(inUseError({ message: 'Invalid.', errors: { name: ['Taken.'] } }))).toBeNull();
        expect(configurationInUse(inUseError({ message: 'Not ready.', code: 'product_not_ready' }))).toBeNull();
        expect(configurationInUse(new Error('network down'))).toBeNull();
        expect(configurationInUse(undefined)).toBeNull();
    });

    it('keeps a countless verdict — the fail-closed "cannot prove unused" case blocks exactly like a count (DEC-20260817-002)', () => {
        const parsed = configurationInUse(
            inUseError({
                code: 'configuration_in_use',
                blocking: [{ code: 'unproven_history', label: 'historical use that could not be proven' }],
                alternative: 'archive',
            }),
        );
        expect(parsed!.blocking).toEqual([
            { code: 'unproven_history', label: 'historical use that could not be proven', count: null },
        ]);
        expect(blockingSentence(parsed!.blocking)).toBe('historical use that could not be proven');
    });

    it('survives a refusal whose blocking list is malformed rather than rendering junk', () => {
        const parsed = configurationInUse(
            inUseError({
                code: 'configuration_in_use',
                blocking: [
                    { code: 'ok', label: 'stock movements', count: '12' },
                    { code: 'no-label', count: 3 },
                    'nonsense',
                ],
            }),
        );
        expect(parsed!.blocking).toEqual([{ code: 'ok', label: 'stock movements', count: 12 }]);
        expect(parsed!.alternative).toBeNull();
    });
});

describe('inUseHeadline', () => {
    it('prefers the server sentence — the backend owns the prose', () => {
        expect(
            inUseHeadline(
                { message: 'Cannot delete item "X" — used by 12 stock movements. Deactivate instead.', blocking: [], alternative: 'archive' },
                'item',
            ),
        ).toBe('Cannot delete item "X" — used by 12 stock movements. Deactivate instead.');
    });

    it('builds one from the counts only when the server sent no message', () => {
        expect(
            inUseHeadline(
                {
                    message: null,
                    blocking: [
                        { code: 'a', label: 'stock movements', count: 12 },
                        { code: 'b', label: 'production batches', count: 2 },
                    ],
                    alternative: 'archive',
                },
                'item',
            ),
        ).toBe('Cannot delete this item — used by 12 stock movements and 2 production batches.');
    });

    it('says something already uses it when neither a message nor a count arrived', () => {
        expect(inUseHeadline({ message: null, blocking: [], alternative: null }, 'mould')).toBe(
            'Cannot delete this mould — something already uses it.',
        );
    });
});

// ---------------------------------------------------------------------------
// "Archive instead" is an offer, not a decoration
// ---------------------------------------------------------------------------

describe('canOfferArchive', () => {
    it('offers it when the server named archive as the alternative and allows archiving', () => {
        expect(canOfferArchive({ alternative: 'archive', can: abilities() })).toBe(true);
    });

    it('does not offer it for an already-retired record — because the SERVER said archive: false', () => {
        // `abilities()` on the backend is `!trashed && isActive && …`, so a
        // retired record arrives with archive: false. Reading that is the whole
        // rule; re-deriving it here from the row would be a second opinion on a
        // question the server has already answered.
        expect(canOfferArchive({ alternative: 'archive', can: abilities({ archive: false }) })).toBe(false);
    });

    it('does not offer it when the server did not name it, or has not answered at all', () => {
        expect(canOfferArchive({ alternative: null, can: abilities() })).toBe(false);
        expect(canOfferArchive({ alternative: 'archive', can: null })).toBe(false);
        expect(canOfferArchive({ alternative: 'archive', can: undefined })).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// The row actions — READ from `can`, never re-derived
// ---------------------------------------------------------------------------

describe('configurationActions', () => {
    it('offers nothing at all when the server sent no `can` block', () => {
        expect(configurationActions(null)).toEqual([]);
        expect(configurationActions(undefined)).toEqual([]);
    });

    it('names the four acts in one vocabulary', () => {
        expect(configurationActions(abilities()).map((a) => a.label)).toEqual(['Edit', 'Reactivate', 'Archive', 'Delete']);
    });

    it('enables exactly what the server enabled and disables the rest', () => {
        const actions = configurationActions(abilities({ edit: true, activate: false, archive: true, delete: false }));
        expect(actions.map((a) => [a.key, a.enabled])).toEqual([
            ['edit', true],
            ['activate', false],
            ['archive', true],
            ['delete', false],
        ]);
    });

    it('gives a refused delete a generic reason — the cause is the report’s to state, not a guess', () => {
        const del = configurationActions(abilities({ delete: false })).find((a) => a.key === 'delete')!;
        expect(del.reason).toBe('Something already uses this record. Archive it instead.');
        expect(del.reason).not.toContain('stock');
    });

    it('treats an undetermined delete as askable rather than allowed or refused', () => {
        const del = configurationActions(abilities({ delete: null })).find((a) => a.key === 'delete')!;
        expect(del.enabled).toBe(true);
        expect(del.reason).toBe('Not checked yet — confirming asks the server what uses it.');
    });

    it('marks only delete as destructive', () => {
        expect(configurationActions(abilities()).filter((a) => a.danger).map((a) => a.key)).toEqual(['delete']);
    });

    it('drops the acts a screen does not offer, without touching what the server allows', () => {
        const actions = configurationActions(abilities(), { activate: false, archive: false });
        expect(actions.map((a) => a.key)).toEqual(['edit', 'delete']);
    });
});

// ---------------------------------------------------------------------------
// The modal's own words
// ---------------------------------------------------------------------------

describe('the delete modal words', () => {
    it('asks with the record in the title', () => {
        expect(deleteModalTitle('mould', 'MLD-04')).toBe('Delete mould “MLD-04”?');
        expect(deleteModalTitle('mould', null)).toBe('Delete this mould?');
    });

    it('says the delete is permanent and the archive is not', () => {
        const body = deleteConfirmBody('mould', 'MLD-04');
        expect(body).toContain('permanently');
        expect(body).toContain('cannot be undone');
    });
});

describe('the fail-closed verdict reaches the reader (DEC-20260817-002 point 5)', () => {
    const refusal = (data: Record<string, unknown>) => ({ response: { data } });

    it('folds the server\'s unprovable list into the blocking reasons', () => {
        const inUse = configurationInUse(
            refusal({
                code: 'configuration_in_use',
                message: null,
                blocking: [{ code: 'stock_movements', label: 'stock movements', count: 12 }],
                unprovable: [{ code: 'legacy_stock_history', label: 'legacy stock history' }],
                alternative: 'archive',
            }),
        );

        expect(inUse).not.toBeNull();
        expect(inUse!.blocking).toHaveLength(2);
        expect(inUse!.blocking[1]).toEqual({
            code: 'legacy_stock_history',
            label: 'legacy stock history',
            count: null,
        });
    });

    it('never renders an empty modal when the ONLY reason is unprovable', () => {
        const inUse = configurationInUse(
            refusal({
                code: 'configuration_in_use',
                message: null,
                blocking: [],
                unprovable: [{ code: 'legacy_stock_history', label: 'legacy stock history' }],
                alternative: 'archive',
            }),
        );

        // The reader must be told SOMETHING; a blank refusal is the failure mode.
        expect(inUse!.blocking).toHaveLength(1);
        expect(blockingLine(inUse!.blocking[0])).toBe('legacy stock history');
        expect(inUseHeadline(inUse!, 'warehouse')).toContain('legacy stock history');
    });

    it('states an uncountable reason without inventing a number', () => {
        expect(blockingLine({ code: 'x', label: 'legacy stock history', count: null })).toBe('legacy stock history');
        expect(blockingLine({ code: 'x', label: 'stock movements', count: 12 })).toBe('12 stock movements');
    });
});

describe('a cascade gap is a visible refusal, never an empty modal', () => {
    const refusal = (data: Record<string, unknown>) => ({ response: { data } });

    it('renders the server\'s cascade-gap message with no invented count', () => {
        const inUse = configurationInUse(
            refusal({
                code: 'configuration_in_use',
                message: null,
                blocking: [],
                unprovable: [],
                cascade_gaps: [
                    {
                        table: 'attendances',
                        column: 'employee_id',
                        reason: 'undeclared',
                        message: 'the schema cascades attendances.employee_id and no check declares it',
                    },
                ],
                alternative: 'archive',
            }),
        );

        expect(inUse!.blocking).toHaveLength(1);
        expect(inUse!.blocking[0].count).toBeNull();
        expect(inUse!.blocking[0].code).toBe('undeclared');
        expect(blockingLine(inUse!.blocking[0])).toContain('attendances.employee_id');
        expect(inUseHeadline(inUse!, 'employee')).toContain('attendances.employee_id');
    });

    it('shows all three lists together when a refusal has every kind of reason', () => {
        const inUse = configurationInUse(
            refusal({
                code: 'configuration_in_use',
                message: null,
                blocking: [{ code: 'stock_balances', label: 'stock balances', count: 3 }],
                unprovable: [{ code: 'legacy', label: 'legacy stock history' }],
                cascade_gaps: [{ table: 't', column: 'c', reason: 'undeclared', message: 'the schema cascades t.c' }],
                alternative: 'archive',
            }),
        );

        expect(inUse!.blocking).toHaveLength(3);
        expect(inUse!.blocking.map((b) => b.count)).toEqual([3, null, null]);
    });
});

// ---------------------------------------------------------------------------
// The reason prompt — one act per sentence
// ---------------------------------------------------------------------------

describe('the reason prompt words', () => {
    it('asks the question that matches the act, in both directions', () => {
        // The archive prompt over a Reactivate box is the kind of mismatched
        // prompt that teaches people to stop reading prompts.
        expect(REASON_REQUIRED).toMatch(/archiv/i);
        expect(REASON_REQUIRED_ACTIVATE).not.toMatch(/archiv/i);
        expect(REASON_REQUIRED_ACTIVATE).toMatch(/service/i);
    });

    it('titles each prompt with the act it is about', () => {
        expect(REASON_MODAL_TITLE.archive).toMatch(/archive/i);
        expect(REASON_MODAL_TITLE.activate).toMatch(/back into service/i);
    });

    it('promises nothing about the reason being kept — no column stores it yet', () => {
        for (const words of [REASON_REQUIRED, REASON_REQUIRED_ACTIVATE]) {
            expect(words).not.toMatch(/kept|saved|stored|recorded with/i);
        }
    });

    /**
     * The offer under a refusal says "Deactivate instead" to match the
     * server's own sentence; the ACT is still called Archive everywhere else.
     * Both words are deliberate, and neither may quietly become the other.
     */
    it('keeps the refusal offer worded the way the server sentence ends', () => {
        expect(ARCHIVE_INSTEAD_LABEL).toBe('Deactivate instead');
    });
});
