export type TallySyncStatus = 'pending' | 'synced' | 'failed' | 'dismissed';

/**
 * What kind of Tally transaction an entry IS — the backend's
 * TallyTransactionCategory::describe(), derived on read from the entry's
 * voucher-type label + source model, never stored. The same shape comes
 * back on every entry AND on every row of the summary's catalogue, so one
 * reader serves both.
 *
 * Two honesty axes, kept apart. `source` is where the transaction LIVES:
 * 'erp' rows are built here and can have entries; 'tally' rows live in
 * the accountant's books (Purchase, Purchase Order, Payment, Receipt,
 * Contra, Credit/Debit Note) and can NEVER have an entry — the page names
 * them as "lives in Tally, not mirrored" rather than showing a zero it
 * never measured; 'absent' is Sales Order — no such voucher type exists in
 * the books at all. `erp_build` is what the ERP has BUILT for it: 'built'
 * for the six ERP categories, 'planned' for the ERP-originated Purchase
 * Order (Phase 6), 'none' otherwise — so a Purchase Order can be in the
 * books AND planned without one word having to say both.
 * `erp_label_differs_from_wire` is true only where the ERP's label is not
 * the voucher type Tally receives — today the per-batch production
 * voucher, labelled "Manufacturing Journal" here but posted as a Stock
 * Journal.
 */
export interface TallyTransactionCategory {
    key: string;
    label: string;
    /** The exact <VOUCHERTYPENAME> on the wire; null when nothing emits one. */
    wire_voucher_type: string | null;
    source: 'erp' | 'tally' | 'absent' | 'unknown';
    erp_build: 'built' | 'planned' | 'none';
    direction: 'erp_to_tally' | 'tally_to_erp' | 'none';
    source_module: string | null;
    erp_label_differs_from_wire: boolean;
}

/**
 * One row of an entry's history (tally_sync_events) — what happened, when,
 * and who did it. `backfilled` marks a row reconstructed from the entry's
 * timestamps by the backfill migration rather than observed as it happened.
 */
export interface TallySyncEvent {
    id: number;
    event: string;
    direction: 'erp_to_tally' | 'tally_to_erp' | 'none';
    occurred_at: string | null;
    actor: {
        /** The three shapes the table knows (TallySyncEvent::ACTOR_*): a person, the agent by its token name, or nobody. */
        type: 'user' | 'agent' | 'system';
        id: number | null;
        label: string | null;
    };
    details: Record<string, unknown> | null;
    backfilled: boolean;
}

/**
 * The entry as a person would say it (backend EntryPresenter::summary):
 * one headline — "<what it is + document> · <party | shift | batch> ·
 * <business date> · <counts> · <status>", segments the payload cannot fill
 * left out — and the lines beneath it. Quantities and counts ONLY: this is
 * not behind the finance gate, so it never carries a rate, an amount or a
 * total (FC-06).
 */
export interface EntrySummary {
    headline: string;
    lines: string[];
}

/**
 * One row of an entry's timeline (EntryPresenter::timeline): the events
 * merged with the entry's own timestamps where no event stands for them,
 * oldest first, one shape for every type. `source` says what produced the
 * row — 'event' was observed as it happened; 'backfill' is the migration's
 * reconstruction from the columns; 'timestamp' is a column read now with
 * no event behind it. `backfilled` is true for both reconstructions.
 */
export interface TimelineItem {
    at: string | null;
    event: string;
    actor_type: 'user' | 'agent' | 'system' | null;
    actor_label: string | null;
    detail: string | null;
    source: 'event' | 'backfill' | 'timestamp';
    backfilled: boolean;
}

/**
 * Honesty flags (EntryPresenter::flags). A key is PRESENT only when raised
 * and always carries `note` (the sentence to show) plus the facts behind
 * it — so `flags.unvalidated_builder` is truthy exactly when the banner is
 * due. Never carries a price.
 */
export interface EntryFlags {
    /**
     * The agent's builder for this category says, in its own docblock, that it is a
     * "BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE" — all
     * four non-production builders do; Sales additionally names its GST gap and the
     * decision that keeps real sales in Tally (`decision`, DEC-20260809-003).
     */
    unvalidated_builder?: { note: string; builder: string; decision?: string };
    /** A Receipt Note carrying tally_order_no / order_due_dates that receiptNote.ts does not emit. */
    order_reference_not_emitted?: { note: string; builder: string; tally_order_no: string | null; order_due_dates: number };
    /** The ERP's label ("Manufacturing Journal") is not the voucher type Tally receives ("Stock Journal"). */
    label_differs_from_wire?: { note: string; erp_label: string; wire_voucher_type: string | null };
    /** The release gate is holding this shift voucher right now — same verdict as `hold`. */
    held?: { note: string; phase: 'collecting' | 'quiet-period'; releasable_at: string };
}

/**
 * How ONE name the voucher hands Tally resolved against the masters as they
 * stand NOW (backend LineMappingResolver — the same resolver the
 * pre-approval preview uses, so preview and detail can never disagree).
 * Derived on every read, stored nowhere: a name unmapped yesterday and
 * mapped today reads as mapped today.
 *
 *   identity   a row carrying a Tally GUID (or, for a ledger role, a configured mapping) — and its note still
 *              says the GUID was recorded at the last masters pull and Tally matches by name; the ERP cannot know
 *   name_only  a row exists by that name but carries no GUID — Tally matches by name and the ERP cannot know if a master so named exists there
 *   unmapped   no row by that name at all
 *   fixture    a LOCAL- rehearsal product — never postable whatever it carries
 *   ambiguous  more than one row shares the name — Tally would match one; the ERP cannot say which (the count is `shared_count`, and in `note`)
 *   none       the line carries no name for that dimension (a Sales line has no godown; a Journal line has no item)
 *   withheld   NOT a resolver state: the party row of a supplier-party voucher (a Receipt Note) for a reader who may
 *              not see who supplied it (FC-06) — the name is null and the note says why (EntryMappingSurface)
 */
export type MappingState = 'identity' | 'name_only' | 'unmapped' | 'fixture' | 'ambiguous' | 'none' | 'withheld';

export interface ItemMapping {
    name: string | null;
    state: MappingState;
    item_id: number | null;
    tally_stock_item_guid: string | null;
    /** How many ERP rows share the name — set on `ambiguous` only, null otherwise; the server counted, nothing here parses. */
    shared_count: number | null;
    note: string | null;
}

export interface GodownMapping {
    name: string | null;
    state: MappingState;
    warehouse_id: number | null;
    /** The GUID Tally will match — the warehouse's own, or its stand-in's under the aliasing rules. */
    tally_guid: string | null;
    /** How an identity was reached: the warehouse's own GUID, a Tally-linked ancestor, or the sole linked godown. */
    resolved_via: 'self' | 'ancestor' | 'sole_linked' | null;
    /** How many ERP rows share the name — set on `ambiguous` only, null otherwise. */
    shared_count: number | null;
    note: string | null;
}

/** A ledger reference — a Journal line's GL account, the party, or the Sales ledger role. */
export interface LedgerMapping {
    name: string | null;
    state: MappingState;
    note: string | null;
}

/**
 * The mapping state of every NAME on the voucher (backend EntryMappingSurface;
 * MASTER-PLAN P3-04) — only on GET /tally-sync/entries/{id}. `lines` walks
 * the stock lines the category's builder writes (`side` says which payload
 * array); `ledgers` is a Journal's GL lines; `party` / `sales_ledger` are
 * present only where that category's payload names one. Names, ids, GUIDs,
 * states and notes only — never a rate (FC-06).
 */
export interface EntryMappings {
    lines: { side: 'produced' | 'consumed' | 'lines' | string; item: ItemMapping; godown: GodownMapping }[];
    ledgers: LedgerMapping[];
    party: LedgerMapping | null;
    sales_ledger: LedgerMapping | null;
}

/** How many names landed in each counted state (`none` is not a mapping outcome and `withheld` was not shown, so neither is counted). */
export type MappingSummary = Record<Exclude<MappingState, 'none' | 'withheld'>, number>;

export interface TallySyncEntry {
    id: number;
    syncable_type: string;
    syncable_id: number;
    tally_voucher_type: string;
    /**
     * What this entry IS. `tally_voucher_type` above stays the raw label the
     * agent dispatches on; `category` says what that label means (and, via
     * erp_label_differs_from_wire, where the two disagree).
     */
    category: TallyTransactionCategory;
    /** The voucher's business date (payload voucher_date, YYYY-MM-DD) — not created_at. */
    business_date: string | null;
    /** The number staff search for in Tally (payload voucher_number). */
    document_number: string | null;
    /**
     * Customer/vendor ledger; null for production and journal vouchers, which
     * carry none — and null for the VENDOR on a Receipt Note when this reader
     * may not see who supplied it (FC-06; the show endpoint's mappings.party
     * then says `withheld`).
     */
    party: string | null;
    /** First item name + how many DISTINCT items the voucher moves; null when it names none. */
    item_summary: { first: string; count: number } | null;
    payload: Record<string, unknown>;
    status: TallySyncStatus;
    attempts: number;
    error_message: string | null;
    /**
     * Present ONLY when Tally's rejection text is withheld from this reader:
     * on a supplier-party voucher, Tally's own words can name the vendor
     * (FC-06, second half), so a reader without standing gets error_message
     * null and this note instead. Never present for finance or the agent.
     */
    error_withheld?: string;
    synced_at: string | null;
    /**
     * When the agent last collected this voucher (TallySyncEntryResource has
     * always sent it). Set but not synced means the agent has it and has not
     * reported back — the signal that separates "the factory machine is off"
     * from "Tally rejected it".
     */
    delivered_at: string | null;
    /** When the accountant pressed "Release now" on a held shift voucher. */
    released_at: string | null;
    /**
     * Why a pending shift voucher is not with the agent yet — null once it
     * is deliverable, and always null for batch vouchers, which are never
     * held. 'collecting' = the shift is still running; 'quiet-period' = the
     * shift ended but an entry merged in less than the idle-hold ago.
     */
    hold?: {
        phase: 'collecting' | 'quiet-period';
        shift_ends_at: string | null;
        last_merged_at: string;
        releasable_at: string;
    } | null;
    created_at: string;
    /**
     * The voucher's whole story after a failure, in order: each retry
     * records the previous error and the regeneration; a dismissal records
     * the write-off ("will never be sent to Tally").
     */
    resolution_log?: { at: string; by: number | null; previous_error?: string | null; note: string }[];
    /** The exact place a recognised Tally refusal is fixed; null for unknown errors. */
    fix?: { sentence: string; path: string } | null;
    /** The human summary — only on GET /tally-sync/entries/{id}, never on the list (same gate as `history`). */
    summary?: EntrySummary;
    /** Events + timestamps merged — only on GET /tally-sync/entries/{id}, never on the list (same gate as `history`). */
    timeline?: TimelineItem[];
    /** Honesty flags — on EVERY response, list included (a page's Sales rows need the unvalidated banner). */
    flags?: EntryFlags;
    /** The entry's event history — only on GET /tally-sync/entries/{id}, never on the list. */
    history?: TallySyncEvent[];
    /** Per-name mapping states — only on GET /tally-sync/entries/{id} (same gate as `history`). */
    mappings?: EntryMappings;
    /** Counts per mapping state over every name judged — only on GET /tally-sync/entries/{id}. */
    mapping_summary?: MappingSummary;
}

/**
 * The server-side filters GET /tally-sync/entries accepts. Every field is
 * optional; buildEntryQuery() (filters.ts) turns this into query params
 * and drops whatever is empty. `from`/`to` are business dates (YYYY-MM-DD,
 * matched against the payload's voucher_date), not created_at.
 */
export interface TallySyncEntryFilters {
    status?: TallySyncStatus[];
    /** TallyTransactionCategory keys — only 'erp' rows can ever match an entry. */
    category?: string[];
    /** Raw wire voucher-type labels ('Sales', 'Stock Journal', ...). */
    voucher_type?: string[];
    from?: string;
    to?: string;
    /** Free text, contains-match over voucher number, party ledger and batch number. */
    q?: string;
    shift_id?: number;
    work_center_id?: number;
    /** true = only entries the release gate is currently holding. */
    held?: boolean;
    /** 'none' is not a filter value: an entry always has a direction (every row is ERP→Tally). */
    direction?: 'erp_to_tally' | 'tally_to_erp';
    /** 'status_rank' = failed → pending → synced → dismissed, newest first within each. */
    sort?: 'status_rank';
}

/** One block of counts on the summary — today's (in the factory timezone) or all-time. */
export interface TallySyncCounts {
    total: number;
    synced: number;
    pending: number;
    failed: number;
    dismissed: number;
    held: number;
}

/**
 * A catalogue row with its measured count. `count` is null — not 0 — for
 * every 'tally' / 'absent' row: nothing was measured because nothing is
 * mirrored, and a zero would claim "measured, none".
 */
export interface TallySyncCategoryCount extends Partial<TallyTransactionCategory> {
    key: string;
    label: string;
    source: TallyTransactionCategory['source'];
    erp_build: TallyTransactionCategory['erp_build'];
    wire_voucher_type: string | null;
    count: number | null;
}

/** GET /tally-sync/summary. */
export interface TallySyncSummary {
    today: TallySyncCounts & { date: string };
    all_time: TallySyncCounts;
    /**
     * How many vouchers the release gate is holding RIGHT NOW, over the
     * request's non-date filters. A state, not a window: `today.held` is
     * bucketed inside today's business date, so the night shift's voucher —
     * dated yesterday, held until 06:00 — is 0 there while it is 1 here.
     */
    held_now: number;
    by_category: TallySyncCategoryCount[];
    /**
     * The last thing the agent DID (a delivery, an ack, a failure report, a
     * masters push), from the events table — null if never. An ACTION, not
     * a contact: a heartbeat poll that finds nothing records no event, so
     * an idle agent can be alive while this stands still.
     */
    agent: {
        last_action_at: string | null;
        last_action_event: string | null;
        last_action_label: string | null;
    };
    last_synced_at: string | null;
    last_masters_pull_at: string | null;
}

export interface AgentToken {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string;
}

export interface LedgerRoleOption {
    value: string;
    label: string;
}

export interface AgentDownload {
    url: string;
    version: string | null;
    built_at: string | null;
    size: number;
}

export interface LedgerOption {
    name: string;
    group: string;
}

export interface TallySettings {
    company: string | null;
    companies: string[];
    roles: LedgerRoleOption[];
    mappings: Record<string, string | null>;
    ledgers: LedgerOption[];
    agent: AgentDownload | null;
}
