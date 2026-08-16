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
 * The short name of a catalogue row: its wire voucher type when it has one,
 * else the label with any parenthetical explanation cut off ("Sales Order
 * (no such voucher type …)" → "Sales Order").
 */
function catalogueName(row: Pick<TallySyncCategoryCount, 'label' | 'wire_voucher_type'>): string {
    return row.wire_voucher_type ?? row.label.replace(/\s*\(.*$/, '').trim();
}

/**
 * The one-line honesty note under the table: which of the accountant's
 * transactions this page does NOT mirror, named — never a zero count, never
 * an empty table implying absence.
 *
 *   "Lives in Tally, not mirrored: Purchase · Payment · … · Sales Order
 *    — Purchase Order: planned (Phase 6)"
 *
 * Built from the summary's catalogue rows with source 'tally' / 'planned',
 * in the order the server lists them (its case order is the catalogue
 * order). The phase on a planned row is read out of the server's own label
 * ("… (planned, Phase 6)"), not typed here — a plan is a factory fact and
 * this file does not invent those. Empty string when the catalogue has no
 * such rows (or has not loaded), so the caller renders nothing rather than
 * a heading with no names under it.
 */
export function catalogueNote(rows: readonly TallySyncCategoryCount[] | null | undefined): string {
    if (!rows || rows.length === 0) return '';

    const inTally = rows.filter((row) => row.source === 'tally').map(catalogueName);
    const planned = rows
        .filter((row) => row.source === 'planned')
        .map((row) => {
            const phase = /Phase\s+\d+/i.exec(row.label)?.[0];

            return `${catalogueName(row)}: planned${phase ? ` (${phase})` : ''}`;
        });

    const parts: string[] = [];
    if (inTally.length > 0) parts.push(`Lives in Tally, not mirrored: ${inTally.join(' · ')}`);
    if (planned.length > 0) parts.push(planned.join(' · '));

    return parts.join(' — ');
}
