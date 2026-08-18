import { describe, expect, it } from 'vitest';
import {
    CONFIGURATION_ENTITIES,
    CONFIGURATION_ENTITY_KEYS,
    configurationEndpoint,
    configurationEntity,
    lifecycleStateOf,
    type ConfigurationEntityKey,
} from './entities';
import { abilitiesOf } from './useConfigurationLifecycle';

// ---------------------------------------------------------------------------
// The registry — one declaration per master the wiring wave covers
// ---------------------------------------------------------------------------

/**
 * The entities the D-WIRING contract names. This list is the "did I wire
 * everything" check: a master that gains lifecycle actions on screen without
 * appearing here is a page that built its own endpoint string, which is the
 * per-page logic the contract exists to remove.
 */
const CONTRACTED: ConfigurationEntityKey[] = [
    'warehouse',
    'item',
    'work-center',
    'mold',
    'shift',
    'scrap-reason',
    'downtime-reason',
    'production-standard',
    'production-standard-packaging',
    'production-configuration',
    'employee',
];

describe('the configuration entity registry', () => {
    it('declares exactly the masters the wiring wave covers', () => {
        expect([...CONFIGURATION_ENTITY_KEYS].sort()).toEqual([...CONTRACTED].sort());
    });

    it('gives every entity a spec keyed by its own name', () => {
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            expect(CONFIGURATION_ENTITIES[key].key).toBe(key);
        }
    });

    it('names each thing in a word the delete confirm can put in a sentence', () => {
        // `deleteModalTitle` renders "Delete this {label}?" — so the label is a
        // lowercase singular noun, never a screen title ("Scrap Reasons").
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            const label = CONFIGURATION_ENTITIES[key].label;
            expect(label).toBe(label.toLowerCase());
            expect(label.length).toBeGreaterThan(0);
        }
    });

    it('points every entity at the API path its module actually serves', () => {
        expect(configurationEndpoint('warehouse')).toBe('inventory/warehouses');
        expect(configurationEndpoint('item')).toBe('inventory/items');
        expect(configurationEndpoint('work-center')).toBe('production/work-centers');
        expect(configurationEndpoint('mold')).toBe('production/molds');
        expect(configurationEndpoint('shift')).toBe('production/shifts');
        expect(configurationEndpoint('scrap-reason')).toBe('production/scrap-reasons');
        expect(configurationEndpoint('downtime-reason')).toBe('production/downtime-reasons');
        expect(configurationEndpoint('production-standard')).toBe('production/standards');
        expect(configurationEndpoint('production-configuration')).toBe('production/configurations');
        expect(configurationEndpoint('employee')).toBe('hrms/employees');
    });

    it('builds a nested resource from its parent, and refuses to guess one', () => {
        expect(configurationEndpoint('production-standard-packaging', 42)).toBe('production/standards/42/packagings');
        // A nested endpoint with no parent would DELETE the wrong URL. It is an
        // error at the call site, not a silently wrong request.
        expect(() => configurationEndpoint('production-standard-packaging')).toThrow(/parent/i);
    });

    it('ignores a parent id for an entity that is not nested', () => {
        expect(configurationEndpoint('warehouse', 42)).toBe('inventory/warehouses');
    });

    it('refreshes at least one query key per entity, so a change is visible without a reload', () => {
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            expect(CONFIGURATION_ENTITIES[key].invalidateKeys.length).toBeGreaterThan(0);
        }
    });

    /**
     * FC-06: purchase rates and supplier identity are Owner/Accounts only, and
     * none of these screens is that. Mechanical, but this is exactly the rule a
     * later "just add a cost column" would break without anyone noticing.
     */
    it('declares nothing priced and nobody supplying (FC-06)', () => {
        const forbidden = /rate|price|amount|cost|salary|ctc|wage|vendor|supplier|purchase/i;
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            expect(JSON.stringify(CONFIGURATION_ENTITIES[key])).not.toMatch(forbidden);
        }
    });

    /**
     * `DeleteConfigurationModal` pre-checks by calling GET {base}/{id}. Where a
     * module serves no `show`, that pre-check is a 404 the modal paints as
     * "could not check what uses this record" on every single row — safe, and
     * unreadable. The registry states which masters may be asked.
     */
    it('says which masters can be asked before the delete is attempted', () => {
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            expect(typeof CONFIGURATION_ENTITIES[key].hasShow).toBe('boolean');
        }
        // Every master in this wave serves `show` (routes/api.php), so every
        // confirm resolves the undetermined verdict before offering the button.
        for (const key of CONFIGURATION_ENTITY_KEYS) {
            expect(CONFIGURATION_ENTITIES[key].hasShow).toBe(true);
        }
    });
});

// ---------------------------------------------------------------------------
// The lifecycle state — the FRONTEND half of ActiveFlag's two predicates
// ---------------------------------------------------------------------------

describe('lifecycleStateOf', () => {
    it('reads an ordinary boolean master', () => {
        const flag = configurationEntity('warehouse');
        expect(lifecycleStateOf(flag, { is_active: true }).state).toBe('active');
        expect(lifecycleStateOf(flag, { is_active: false }).state).toBe('retired');
    });

    it('says nothing at all when the row carries no state to read', () => {
        // Never "Retired" by default: a payload that predates the column would
        // print every row as gone.
        expect(lifecycleStateOf(configurationEntity('warehouse'), {}).state).toBe('unknown');
    });

    it('reads a status master, and leaves the middle case as neither', () => {
        const flag = configurationEntity('mold');
        expect(lifecycleStateOf(flag, { status: 'active' }).state).toBe('active');
        expect(lifecycleStateOf(flag, { status: 'retired' }).state).toBe('retired');
        // ActiveFlag's whole point: an under-repair mould is NOT active (it may
        // be activated) and NOT retired (it may still be archived).
        const between = lifecycleStateOf(flag, { status: 'under_repair' });
        expect(between.state).toBe('between');
        expect(between.label).toBe('Under repair');
    });

    it('treats a draft configuration as neither in service nor retired', () => {
        const flag = configurationEntity('production-configuration');
        expect(lifecycleStateOf(flag, { status: 'approved' }).state).toBe('active');
        expect(lifecycleStateOf(flag, { status: 'inactive' }).state).toBe('retired');
        expect(lifecycleStateOf(flag, { status: 'draft' }).state).toBe('between');
    });

    it('reads a soft-delete-only master from the is_archived the resource sends', () => {
        for (const key of ['production-standard', 'production-standard-packaging'] as const) {
            const flag = configurationEntity(key);
            expect(lifecycleStateOf(flag, { is_archived: false }).state).toBe('active');
            // TRUE is the retired side — reading it the other way round would
            // print "Active" over every archived row.
            expect(lifecycleStateOf(flag, { is_archived: true }).state).toBe('retired');
            expect(lifecycleStateOf(flag, {}).state).toBe('unknown');
        }
    });

    it('states an unrecognised status verbatim rather than calling it active', () => {
        const flag = configurationEntity('mold');
        const odd = lifecycleStateOf(flag, { status: 'awaiting_survey' });
        expect(odd.state).toBe('unknown');
        expect(odd.label).toBe('awaiting_survey');
    });

    /**
     * Several masters carry SoftDeletes AND an active flag, and the two can
     * disagree — a Tally pull can restore a trashed warehouse whose is_active
     * is still true. "Active" printed over an archived record is the exact lie
     * the contract exists to stop, so the archived axis wins.
     */
    it('lets the archived axis overrule an active flag that still says otherwise', () => {
        expect(
            lifecycleStateOf(configurationEntity('warehouse'), { is_active: true, archived_at: '2026-08-17T10:00:00+05:30' })
                .state,
        ).toBe('retired');
        expect(lifecycleStateOf(configurationEntity('warehouse'), { is_active: true, archived_at: null }).state).toBe(
            'active',
        );
        expect(
            lifecycleStateOf(configurationEntity('production-configuration'), { status: 'approved', is_archived: true })
                .state,
        ).toBe('retired');
        expect(lifecycleStateOf(configurationEntity('employee'), { status: 'active', is_archived: true }).state).toBe(
            'retired',
        );
        // And it never invents the other direction: not archived says nothing
        // on its own, the master's own flag still decides.
        expect(lifecycleStateOf(configurationEntity('employee'), { is_archived: false }).state).toBe('unknown');
    });

    it('uses the two product-wide words for the two states the contract names', () => {
        expect(lifecycleStateOf(configurationEntity('shift'), { is_active: true }).label).toBe('Active');
        expect(lifecycleStateOf(configurationEntity('shift'), { is_active: false }).label).toBe('Retired');
    });
});

// ---------------------------------------------------------------------------
// Reading the server's `can` — the one thing the row actions are allowed to use
// ---------------------------------------------------------------------------

describe('abilitiesOf', () => {
    it('reads the can block off a `show` envelope', () => {
        const can = abilitiesOf({ data: { id: 1, can: { edit: true, activate: false, archive: true, delete: false } } });
        expect(can).toEqual({ edit: true, activate: false, archive: true, delete: false });
    });

    it('reads it off a bare record too', () => {
        expect(abilitiesOf({ can: { edit: true, activate: true, archive: false, delete: true } })).toEqual({
            edit: true,
            activate: true,
            archive: false,
            delete: true,
        });
    });

    it('is null when the server sent no can block — never an invented "allowed"', () => {
        expect(abilitiesOf({ data: { id: 1 } })).toBeNull();
        expect(abilitiesOf(null)).toBeNull();
        expect(abilitiesOf('nonsense')).toBeNull();
    });

    it('treats anything that is not a boolean delete as UNDETERMINED', () => {
        expect(abilitiesOf({ can: { edit: true, activate: true, archive: true } })?.delete).toBeNull();
        expect(abilitiesOf({ can: { edit: true, activate: true, archive: true, delete: null } })?.delete).toBeNull();
        expect(abilitiesOf({ can: { edit: true, activate: true, archive: true, delete: 'yes' } })?.delete).toBeNull();
    });

    it('never turns a missing flag into permission', () => {
        const can = abilitiesOf({ can: {} });
        expect(can).toEqual({ edit: false, activate: false, archive: false, delete: null });
    });
});
