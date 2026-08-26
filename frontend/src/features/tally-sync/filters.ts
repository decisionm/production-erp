import type {
    TallySyncCategoryCount,
    TallySyncEntryFilters,
    TallyTransactionCategory,
} from './types';

/**
 * Pure helpers behind the Tally Sync page's filter bar. No React, no axios
 * — everything here is a function of its arguments so it can be tested
 * without a DOM (filters.test.ts).
 */

/** What buildEntryQuery() hands to axios as `params`. */
export type EntryQuery = Record<string, string | string[] | number | boolean>;

/**
 * The filter keys the server knows. buildEntryQuery() reads ONLY these off
 * its argument, on purpose: listTallySyncEntries / listAllTallySyncEntries
 * are handed straight to useQuery as a queryFn in other features
 * (Dashboard, Live Monitor), which calls them with TanStack's context
 * object — {queryKey, signal, meta, ...} — as the first argument. An
 * allowlist means that object contributes nothing to the URL instead of
 * being posted as query params wholesale.
 */
const FILTER_KEYS = [
    'status',
    'category',
    'voucher_type',
    'from',
    'to',
    'q',
    'shift_id',
    'work_center_id',
    'held',
    'direction',
    'sort',
] as const satisfies readonly (keyof TallySyncEntryFilters)[];

/**
 * A business date on the wire is YYYY-MM-DD, full stop. The RangePicker
 * already emits that; anything ISO-shaped with a time part is cut to its
 * date so a caller passing an instant does not send a string the server's
 * date validation refuses.
 */
function businessDay(value: string): string {
    return /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : value;
}

/**
 * Filters → axios `params`, with everything empty left out.
 *
 * Arrays are passed THROUGH as arrays, not joined: the shared axios
 * instance (@/lib/api.ts) sets no paramsSerializer, so axios's default
 * bracket form applies — `status[]=failed&status[]=pending` — which is
 * exactly what Laravel reads as `status => ['failed', 'pending']`. Joining
 * with commas here would hand the server one string it does not split.
 *
 * `held` goes out as `1`, never `true`: the codebase's boolean-flag
 * convention on the wire (production `active: 1|0`, quality `due: 1`),
 * and the form both Laravel's `boolean` rule and $request->boolean()
 * accept. `held: false` is "no filter", not "only unheld", so it is dropped
 * like any other empty value.
 */
export function buildEntryQuery(filters: TallySyncEntryFilters | null | undefined): EntryQuery {
    const query: EntryQuery = {};
    if (!filters || typeof filters !== 'object') {
        return query;
    }

    for (const key of FILTER_KEYS) {
        const value = filters[key];

        if (value === undefined || value === null) continue;

        if (Array.isArray(value)) {
            const kept = value.filter((v): v is string => typeof v === 'string' && v.trim() !== '');
            if (kept.length > 0) query[key] = kept;
            continue;
        }

        if (typeof value === 'boolean') {
            if (value) query[key] = 1;
            continue;
        }

        if (typeof value === 'number') {
            if (Number.isFinite(value)) query[key] = value;
            continue;
        }

        const text = value.trim();
        if (text === '') continue;

        query[key] = key === 'from' || key === 'to' ? businessDay(text) : text;
    }

    return query;
}

/**
 * True when the user has narrowed the list — i.e. the rows on screen are a
 * subset the honesty copy must not describe as "everything". `sort` is not
 * a filter: it changes order, not membership.
 */
export function hasActiveFilters(filters: TallySyncEntryFilters | null | undefined): boolean {
    const query = buildEntryQuery(filters);
    delete query.sort;

    return Object.keys(query).length > 0;
}

/**
 * The category as the table shows it. Where the ERP's label is not the
 * voucher type Tally receives, the wire type is said out loud next to it —
 * "… · posts as Stock Journal" — so a row labelled "Manufacturing Journal"
 * never lets anyone go looking for a Manufacturing Journal in the books.
 */
export function categoryLabel(
    // The flag is optional so a summary catalogue row (which may not carry
    // it) reads the same as an entry's category; absent = no suffix.
    category:
        | (Pick<TallyTransactionCategory, 'label' | 'wire_voucher_type'> & { erp_label_differs_from_wire?: boolean })
        | null
        | undefined,
): string {
    if (!category) return '—';

    if (category.erp_label_differs_from_wire && category.wire_voucher_type) {
        return `${category.label} · posts as ${category.wire_voucher_type}`;
    }

    return category.label;
}

/**
 * The category dropdown's options: only the categories that can have an
 * entry — the ones the ERP builds, plus `unknown`, a real, measured bucket
 * (rows the classifier could not place) that was unreachable from the
 * filter bar while the dropdown read `source === 'erp'` alone. A Tally-only
 * or absent row can never match an entry, so offering it would be a filter
 * that always returns nothing. Order is the server's catalogue order.
 */
export function categoryFilterOptions(
    rows: readonly TallySyncCategoryCount[] | null | undefined,
): { value: string; label: string }[] {
    return (rows ?? [])
        .filter((row) => row.source === 'erp' || row.key === 'unknown')
        .map((row) => ({ value: row.key, label: categoryLabel(row) }));
}

/**
 * The voucher-type dropdown's options — the RAW `tally_voucher_type`
 * labels the queue actually holds, as the server reports them
 * (summary.voucher_types), in the server's order.
 *
 * RAW, not the wire type, because that is the column the server filter
 * matches. The two differ by design on at least one category: a batch-mode
 * production voucher is labelled "Manufacturing Journal" on the entry and
 * posts to Tally as a Stock Journal, so wire types offered here would be a
 * filter that always returns nothing.
 *
 * Voucher TYPES only. Nothing about a party, a supplier, a rate, an amount
 * or a payload is offered or enumerated by this control — those are FC-06
 * questions and the filter bar does not ask them.
 *
 * Empty until the summary answers, so the control renders with no options
 * rather than inventing a list the queue may not contain.
 */
export function voucherTypeFilterOptions(
    types: readonly string[] | null | undefined,
): { value: string; label: string }[] {
    return (types ?? [])
        .filter((type): type is string => typeof type === 'string' && type.trim() !== '')
        .map((type) => ({ value: type, label: type }));
}

/** One business-date preset: the label a person clicks and the range it means. */
export interface DatePreset {
    label: string;
    from: string;
    to: string;
}

/**
 * The business-date filter's presets, computed from a YYYY-MM-DD "today"
 * the caller supplies.
 *
 * TODAY IS AN ARGUMENT, not something read from the clock in here, for the
 * same reason voucherDate() does no parsing: the range is matched against
 * payload.voucher_date, which is the FACTORY's day, and the browser's day
 * is not necessarily it — a supervisor's tablet at 00:30 IST and the
 * server's UTC clock disagree about what "today" is by a whole date. The
 * page passes the factory day the summary already reports
 * (summary.today.date), so "Today" here means the same day the counts
 * beside it mean.
 *
 * "Last 7 days" INCLUDES today — seven dates ending today, the ordinary
 * reading of the phrase on a queue where today's vouchers are the ones
 * being chased.
 */
export function businessDatePresets(today: string | null | undefined): DatePreset[] {
    if (!today || !/^\d{4}-\d{2}-\d{2}$/.test(today)) return [];

    return [
        { label: 'Today', from: today, to: today },
        { label: 'Yesterday', from: shiftDays(today, -1), to: shiftDays(today, -1) },
        { label: 'Last 7 days', from: shiftDays(today, -6), to: today },
    ];
}

/**
 * A YYYY-MM-DD date moved by whole days, staying a calendar date.
 *
 * Date.UTC — never the local constructor — so the arithmetic cannot be
 * shifted by the viewer's offset or by a DST jump: a UTC day is exactly
 * 86,400,000 ms, a local one is not, and "yesterday" landing on the wrong
 * date twice a year is the kind of bug nobody reports and everybody
 * mis-reconciles.
 */
function shiftDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const moved = new Date(Date.UTC(year, month - 1, day + days));

    return moved.toISOString().slice(0, 10);
}

/**
 * How many rows the classifier could not place — the `unknown` catalogue
 * count, which is measured like the ERP rows' (never null). 0 when the
 * catalogue has not loaded or carries no such row, so a header can render
 * nothing rather than "unclassified: ?".
 */
export function unclassifiedCount(rows: readonly TallySyncCategoryCount[] | null | undefined): number {
    return rows?.find((row) => row.key === 'unknown')?.count ?? 0;
}

/**
 * The short name of a catalogue row: its wire voucher type when it has one,
 * else the label with any parenthetical explanation cut off ("Sales Order
 * (no such voucher type …)" → "Sales Order").
 */
function catalogueName(row: Pick<TallySyncCategoryCount, 'label' | 'wire_voucher_type'>): string {
    return row.wire_voucher_type ?? row.label.replace(/\s*\(.*$/, '').trim();
}

/** The decision id the server wrote into a label ("… DEC-20260809-003)"), if any — never typed here. */
function decisionIn(label: string): string | undefined {
    return /DEC-\d{8}-\d{3}/.exec(label)?.[0];
}

/**
 * The honesty note under the table, one clause per line: which of the
 * accountant's transactions this page does NOT mirror, named — never a zero
 * count, never an empty table implying absence.
 *
 *   "Lives in Tally, not mirrored: Purchase · Payment · … · Debit Note"
 *   "<name>: ERP-originated version planned (Phase N)"   — only when a row is planned
 *   "Sales Order: no such voucher type in Tally — sales are invoiced there (DEC-20260809-003)"
 *
 * Built from the summary's catalogue rows on the server's TWO axes: source
 * 'tally' rows are the first clause, in the order the server lists them
 * (its case order is the catalogue order); erp_build 'planned' rows are the
 * second — a row can be BOTH in the books and planned, and both are said;
 * source 'absent' rows are the third. Purchase Order is NOT in any clause
 * since Phase 6: the ERP BUILDS and STAGES it (source 'erp', erp_build
 * 'built'; live posting owner-gated, flag off — Q35), so it sits in the
 * table as an ERP row with an honest 0, not in this note. No served row is
 * planned today; the clause is kept for the next one. The phase on a
 * planned row and the decision on an absent row are read out of the
 * server's own label, not typed here — a plan and a decision are factory
 * facts and this file does not invent those. Empty when the catalogue has
 * no such rows (or has not loaded), so the caller renders nothing rather
 * than a heading with no names under it.
 */
export function catalogueNote(rows: readonly TallySyncCategoryCount[] | null | undefined): string[] {
    if (!rows || rows.length === 0) return [];

    const inTally = rows.filter((row) => row.source === 'tally').map(catalogueName);
    const planned = rows
        .filter((row) => row.erp_build === 'planned')
        .map((row) => {
            const phase = /Phase\s+\d+/i.exec(row.label)?.[0];

            return `${catalogueName(row)}: ERP-originated version planned${phase ? ` (${phase})` : ''}`;
        });
    const absent = rows
        .filter((row) => row.source === 'absent')
        .map((row) => {
            // The label's parenthetical is "<what>; <why> — DEC-…": the why
            // is the server's words, repeated here minus the decision id,
            // which goes in brackets at the end.
            const parenthetical = /\(([^)]*)\)/.exec(row.label)?.[1] ?? '';
            const decision = decisionIn(parenthetical);
            const reason = parenthetical.split(';')[1]?.replace(/[\s—–-]*DEC-\d{8}-\d{3}/, '').trim();

            return `${catalogueName(row)}: no such voucher type in Tally`
                + `${reason ? ` — ${reason}` : ''}${decision ? ` (${decision})` : ''}`;
        });

    const clauses: string[] = [];
    if (inTally.length > 0) clauses.push(`Lives in Tally, not mirrored: ${inTally.join(' · ')}`);
    clauses.push(...planned, ...absent);

    return clauses;
}
