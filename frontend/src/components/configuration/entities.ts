/**
 * THE REGISTRY — which masters carry the Configuration Lifecycle Contract on
 * screen, where each one lives on the wire, and what its own column calls
 * "in service".
 *
 * Phase 7.6 shipped the mechanism and wired nothing; this module is the
 * frontend half of the wiring. It exists rather than a literal per page for
 * two reasons:
 *
 *  1. **One endpoint string per master, in one place.** A page that spelled
 *     its own `production/molds` would be a second copy of the routing, and a
 *     second copy is the one that goes stale.
 *  2. **`ActiveFlag`, in TypeScript.** The backend's `ActiveFlag` keeps TWO
 *     predicates apart — is it active, is it retired — because a status
 *     master has a middle case that is neither (a mould `under_repair`, a
 *     `draft` configuration). A tag that derived one from the other would say
 *     "Retired" over a mould that is only being fixed. So the shape of each
 *     master's state column is declared here, once, and the tag reads it.
 *
 * What this module deliberately does NOT hold: anything about ELIGIBILITY.
 * Whether a record may be edited, archived, reactivated or deleted is the
 * server's `can` block and nothing else (DEC-20260817-002 — hard delete is
 * Super Admin / Owner level, decided server-side). Nothing here is consulted
 * to enable a button.
 */

import { statusWords } from './configurationWords';

export type ConfigurationEntityKey =
    | 'warehouse'
    | 'item'
    | 'work-center'
    | 'mold'
    | 'shift'
    | 'scrap-reason'
    | 'downtime-reason'
    | 'production-standard'
    | 'production-standard-packaging'
    | 'production-configuration'
    | 'employee'
    | 'vendor'
    | 'customer';

/**
 * How a master says it is in service — mirroring `App\Support\Configuration\
 * ActiveFlag` case for case.
 *
 *  - `boolean`  the ordinary `is_active` column.
 *  - `status`   a BackedEnum column: the case that means IN SERVICE, the case
 *               archiving writes, and the words for every case that is
 *               NEITHER (which stays reachable in both directions).
 *  - `archived` a master with no state column at all, where archiving IS the
 *               soft delete. The resource says so with a boolean
 *               (`is_archived`), so TRUE is the retired side.
 *  - `null`     nothing on the row says. The tag then says nothing either.
 */
export type LifecycleFlag =
    | { kind: 'boolean'; column: string }
    | { kind: 'status'; column: string; active: string; retired: string; between: Record<string, string> }
    | { kind: 'archived'; column: string }
    | null;

export interface ConfigurationEntitySpec {
    key: ConfigurationEntityKey;
    /** The noun a refusal and a confirm print — lowercase, singular. */
    label: string;
    /**
     * The REST base, relative to `/api/v1`. A FUNCTION when the resource is
     * nested under a parent (a packaging belongs to one standard), so the base
     * cannot be known without it.
     */
    endpoint: string | ((parentId: number | string) => string);
    /**
     * Does this module serve `GET <base>/{id}`?
     *
     * `DeleteConfigurationModal` resolves an undetermined `can.delete` by
     * asking `show` first — an index row cannot carry that verdict, because a
     * full dependency sweep is 8-30 COUNTs PER ROW. Where a module served no
     * `show`, asking would 404 and the modal would paint "could not check what
     * uses this record" over every row: safe, and it reads as broken. Every
     * master in this registry serves one, so every confirm asks; the flag
     * stays because the next wave's masters may not.
     */
    hasShow: boolean;
    flag: LifecycleFlag;
    /**
     * The SECOND state axis, where a master has one.
     *
     * Several of these tables carry SoftDeletes AND an active flag, and the
     * two can disagree: a Tally pull can restore a trashed warehouse whose
     * `is_active` is still true, and `abilities()` keys `activate` off
     * `$trashed || …` precisely because being trashed is its own state. A tag
     * reading the active flag alone would then print "Active" over an
     * archived record — the exact lie this contract exists to stop. So when
     * the resource says the row is archived, that answer wins.
     *
     * `boolean` = the column is `true` when archived; `timestamp` = the
     * column carries a date when archived and null when it is not.
     */
    archivedWhen?: { column: string; kind: 'boolean' | 'timestamp' };
    /** Every list that shows this master, refreshed after a lifecycle change. */
    invalidateKeys: ReadonlyArray<readonly unknown[]>;
}

const spec = (s: ConfigurationEntitySpec): ConfigurationEntitySpec => s;

export const CONFIGURATION_ENTITIES: Record<ConfigurationEntityKey, ConfigurationEntitySpec> = {
    warehouse: spec({
        key: 'warehouse',
        label: 'warehouse',
        endpoint: 'inventory/warehouses',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        archivedWhen: { column: 'archived_at', kind: 'timestamp' },
        invalidateKeys: [['inventory', 'warehouses']],
    }),
    vendor: spec({
        key: 'vendor',
        label: 'vendor',
        endpoint: 'procurement/vendors',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        archivedWhen: { column: 'archived_at', kind: 'timestamp' },
        invalidateKeys: [['procurement', 'vendors']],
    }),
    customer: spec({
        key: 'customer',
        label: 'customer',
        endpoint: 'sales/customers',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        archivedWhen: { column: 'archived_at', kind: 'timestamp' },
        invalidateKeys: [['sales', 'customers']],
    }),
    item: spec({
        key: 'item',
        label: 'item',
        endpoint: 'inventory/items',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        archivedWhen: { column: 'archived_at', kind: 'timestamp' },
        invalidateKeys: [['inventory', 'items']],
    }),
    'work-center': spec({
        key: 'work-center',
        label: 'machine',
        endpoint: 'production/work-centers',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        // The machine master is read by the standards workspace too — a
        // retired machine must stop being offered there in the same click.
        invalidateKeys: [['production', 'work-centers'], ['production', 'configurations']],
    }),
    mold: spec({
        key: 'mold',
        label: 'mould',
        endpoint: 'production/molds',
        hasShow: true,
        flag: {
            kind: 'status',
            column: 'status',
            active: 'active',
            retired: 'retired',
            // Neither active nor retired, and the factory's own word for it.
            between: { under_repair: 'Under repair' },
        },
        invalidateKeys: [['production', 'molds']],
    }),
    shift: spec({
        key: 'shift',
        label: 'shift',
        endpoint: 'production/shifts',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        invalidateKeys: [['production', 'shifts']],
    }),
    'scrap-reason': spec({
        key: 'scrap-reason',
        label: 'scrap reason',
        endpoint: 'production/scrap-reasons',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        invalidateKeys: [['production', 'scrap-reasons']],
    }),
    'downtime-reason': spec({
        key: 'downtime-reason',
        label: 'downtime reason',
        endpoint: 'production/downtime-reasons',
        hasShow: true,
        flag: { kind: 'boolean', column: 'is_active' },
        invalidateKeys: [['production', 'downtime-reasons']],
    }),
    'production-standard': spec({
        key: 'production-standard',
        label: 'product standard',
        endpoint: 'production/standards',
        hasShow: true,
        // No state column: the model carries SoftDeletes and nothing else, so
        // archiving IS the soft delete, and the resource reports it as
        // `is_archived` rather than leaking `deleted_at`.
        flag: { kind: 'archived', column: 'is_archived' },
        invalidateKeys: [['production', 'standards']],
    }),
    'production-standard-packaging': spec({
        key: 'production-standard-packaging',
        label: 'packing option',
        endpoint: (parentId) => `production/standards/${parentId}/packagings`,
        hasShow: true,
        // Migration 2026_08_18_090000 gave this master somewhere to be
        // archived TO — SoftDeletes and nothing else — so, like its parent
        // standard, `is_archived` is its whole state.
        flag: { kind: 'archived', column: 'is_archived' },
        invalidateKeys: [['production', 'standards']],
    }),
    'production-configuration': spec({
        key: 'production-configuration',
        label: 'machine exception',
        endpoint: 'production/configurations',
        hasShow: true,
        flag: {
            kind: 'status',
            column: 'status',
            active: 'approved',
            retired: 'inactive',
            // A draft is not in service and is not retired — it is waiting for
            // the approval the module already has.
            between: { draft: 'Draft' },
        },
        archivedWhen: { column: 'is_archived', kind: 'boolean' },
        invalidateKeys: [['production', 'configurations'], ['production', 'standards']],
    }),
    employee: spec({
        key: 'employee',
        label: 'employee',
        endpoint: 'hrms/employees',
        hasShow: true,
        flag: {
            kind: 'status',
            column: 'status',
            active: 'active',
            retired: 'inactive',
            // `terminated` is an HR fact, not the configuration state — it is
            // shown in the factory's own word rather than folded into
            // "Retired", which would lose what payroll needs to read.
            between: { terminated: 'Terminated' },
        },
        archivedWhen: { column: 'is_archived', kind: 'boolean' },
        invalidateKeys: [['hrms', 'employees']],
    }),
};

export const CONFIGURATION_ENTITY_KEYS = Object.keys(CONFIGURATION_ENTITIES) as ConfigurationEntityKey[];

export const configurationEntity = (key: ConfigurationEntityKey): ConfigurationEntitySpec =>
    CONFIGURATION_ENTITIES[key];

/**
 * The REST base for one master. A nested resource REFUSES to build itself
 * without its parent: a DELETE sent to a half-built path is a request against
 * the wrong URL, which is not something to discover in production.
 */
export function configurationEndpoint(key: ConfigurationEntityKey, parentId?: number | string | null): string {
    const { endpoint } = CONFIGURATION_ENTITIES[key];
    if (typeof endpoint === 'string') return endpoint;
    if (parentId === undefined || parentId === null || parentId === '') {
        throw new Error(`The ${key} resource is nested — it needs its parent id to build an endpoint.`);
    }
    return endpoint(parentId);
}

// ---------------------------------------------------------------------------
// The state on the row
// ---------------------------------------------------------------------------

export type LifecycleState = 'active' | 'retired' | 'between' | 'unknown';

export interface LifecycleStateWords {
    state: LifecycleState;
    /** What the tag prints. "Active"/"Retired" for the two contracted states. */
    label: string;
    /** What the state MEANS for picking the record. */
    description: string;
}

const BETWEEN_DESCRIPTION =
    'Neither in service nor retired — it is not offered for new work, and it can still be reactivated or archived.';

/**
 * The row is taken as `unknown` on purpose: every caller passes a typed
 * module interface (a `Warehouse`, a `Mold`), and none of them carries an
 * index signature. Narrowing here once beats a cast at every call site.
 */
const asRow = (row: unknown): Record<string, unknown> | null =>
    row !== null && typeof row === 'object' ? (row as Record<string, unknown>) : null;

/**
 * The record's lifecycle state, read through the master's own column.
 *
 * `unknown` is a real answer and it is deliberately NOT "retired": a payload
 * that arrives without the column (an older backend, a projection that never
 * selected it) must render nothing rather than print every row as gone.
 */
export function lifecycleStateOf(spec: ConfigurationEntitySpec | LifecycleFlag, row: unknown): LifecycleStateWords {
    const unknown = (label = ''): LifecycleStateWords => ({ state: 'unknown', label, description: '' });
    const bag = asRow(row);
    // Callers pass the whole spec; the bare flag stays accepted so a caller
    // that only has one is not forced to invent the rest.
    const full = spec !== null && 'key' in spec ? spec : null;
    const flag = full === null ? (spec as LifecycleFlag) : full.flag;

    if (bag === null) return unknown();

    // The archived axis WINS. It is the one answer that cannot be wrong: a
    // trashed row is out of service whatever its own flag still says.
    const archived = full?.archivedWhen;
    if (archived !== undefined) {
        const mark = bag[archived.column];
        if (archived.kind === 'boolean' ? mark === true : mark !== null && mark !== undefined && mark !== '') {
            return words(false);
        }
    }

    if (flag === null) return unknown();

    const raw = bag[flag.column];

    if (flag.kind === 'archived') {
        if (typeof raw !== 'boolean') return unknown();
        // TRUE is the RETIRED side — the column names the archived state, not
        // the live one.
        return words(!raw);
    }

    if (flag.kind === 'boolean') {
        if (typeof raw !== 'boolean') return unknown();
        return words(raw);
    }

    const value = typeof raw === 'string' || typeof raw === 'number' ? String(raw) : null;
    if (value === null) return unknown();
    if (value === flag.active) return words(true);
    if (value === flag.retired) return words(false);
    const between = flag.between[value];
    if (between !== undefined) return { state: 'between', label: between, description: BETWEEN_DESCRIPTION };
    // A case nobody declared. Stated verbatim — calling it Active would be
    // this screen deciding a factory state it has never been told about.
    return unknown(value);
}

const words = (active: boolean): LifecycleStateWords => {
    const w = statusWords(active);
    return { state: active ? 'active' : 'retired', label: w.label, description: w.description };
};
