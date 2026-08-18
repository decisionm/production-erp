/**
 * The shapes the Configuration Lifecycle Contract puts on the wire
 * (Phase 7.6, `docs/engineering/AUDIT-CONFIGURATION-LIFECYCLE-2026-08-17.md`).
 *
 * Every applicable master answers `Create → View → Edit → Activate/Deactivate
 * → Safe Delete → Audit`, and the BACKEND is the only thing that decides which
 * of those a given record allows. These types exist so the frontend can READ
 * that decision; nothing here re-derives it.
 */

/**
 * The server's `can` block, on every configuration resource.
 *
 * `delete: null` is not "no" — it is UNDETERMINED. A full dependency sweep is
 * 8–30 COUNTs per row, so `index` returns the cheap flags with `delete: null`
 * and only `show` (and the delete itself) return an authoritative verdict.
 * The confirm modal is what resolves it.
 *
 * Who may delete at all is the server's call too: hard delete is Super
 * Admin / Owner level only (DEC-20260817-002). The UI never checks a role.
 */
export interface ConfigurationAbilities {
    edit: boolean;
    activate: boolean;
    archive: boolean;
    delete: boolean | null;
}

/** One reason a delete is refused, as the 422 carries it. */
export interface BlockingReason {
    /** A stable machine key — for tests and for grouping, never shown raw. */
    code: string;
    /** The server's own words for what references the record ("stock movements"). */
    label: string;
    /**
     * How many. `null` when the server stated a verdict it could not count —
     * the fail-closed "historical use cannot be proven" case, which blocks the
     * delete exactly like a positive count (DEC-20260817-002 point 5).
     */
    count: number | null;
}

/** A `configuration_in_use` refusal, read off the 422. */
export interface ConfigurationInUse {
    /** The server's sentence. It owns the prose; the UI only falls back if it is absent. */
    message: string | null;
    blocking: BlockingReason[];
    /** What to do instead — `'archive'` today. */
    alternative: string | null;
}

/** The four acts of the contract, in the order they are offered. */
export type ConfigurationActionKey = 'edit' | 'activate' | 'archive' | 'delete';

export interface ConfigurationAction {
    key: ConfigurationActionKey;
    label: string;
    /** Destructive — delete alone. */
    danger: boolean;
    /** Straight from the server's `can`. Never computed from the record. */
    enabled: boolean;
    /** Why it is disabled, or what confirming will do. Generic on purpose — see configurationWords. */
    reason: string | null;
}
