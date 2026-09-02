/**
 * THE URL IS THE LIST'S STATE — search, filters, page and page size.
 *
 * The Sales and Procurement list pages each grew their own copy of this
 * (useSalesListParams, usePurchaseOrderListParams); every other list kept
 * its filters in component state, so a refresh, the Back button or a
 * pasted link lost the view. This module is the shared, pure half: how a
 * URL reads into a params object and how a params object writes back.
 * `useListParams` is the React half.
 *
 * Pure — no React, no axios — so the round trip is pinned by an ordinary
 * vitest (listParams.test.ts).
 */

/** The server's per_page ceiling (1..100). */
export const MAX_PER_PAGE = 100;

export type ListParamValue = string | number | string[] | undefined;

/** `q`, `page` and `per_page` are every list's; the rest is the page's own. */
export type ListParams = {
    q?: string;
    page?: number;
    per_page?: number;
} & Record<string, ListParamValue>;

/**
 * Which of a page's own keys exist, and what shape each takes on the URL.
 * `q`, `page` and `per_page` are implied and need not be listed. Anything
 * not named here is dropped on read, so a stray key can never reach the
 * server through this door.
 */
export interface ListParamsSpec {
    /** Free-text keys, copied trimmed. */
    strings?: readonly string[];
    /** Positive-integer keys (ids, years). */
    numbers?: readonly string[];
    /** Multi-valued keys, comma-joined on the URL. */
    lists?: readonly string[];
    /** Allowed values per key (string or list); anything else is dropped. */
    allowed?: Record<string, readonly string[]>;
}

function positiveInt(raw: string | null): number | undefined {
    if (raw === null || !/^\d+$/.test(raw.trim())) return undefined;
    const value = Number(raw);

    return Number.isInteger(value) && value > 0 ? value : undefined;
}

function nonEmpty(raw: string | null): string | undefined {
    const value = raw?.trim() ?? '';

    return value === '' ? undefined : value;
}

function permitted(spec: ListParamsSpec, key: string, value: string): boolean {
    const allowed = spec.allowed?.[key];

    return allowed === undefined || allowed.includes(value);
}

/** URL → params. Invalid or unknown values are dropped, never coerced. */
export function readListParams(searchParams: URLSearchParams, spec: ListParamsSpec): ListParams {
    const params: ListParams = {};

    const q = nonEmpty(searchParams.get('q'));
    if (q !== undefined) params.q = q;

    const page = positiveInt(searchParams.get('page'));
    if (page !== undefined && page > 1) params.page = page;

    const perPage = positiveInt(searchParams.get('per_page'));
    if (perPage !== undefined && perPage <= MAX_PER_PAGE) params.per_page = perPage;

    for (const key of spec.strings ?? []) {
        const value = nonEmpty(searchParams.get(key));
        if (value !== undefined && permitted(spec, key, value)) params[key] = value;
    }

    for (const key of spec.numbers ?? []) {
        const value = positiveInt(searchParams.get(key));
        if (value !== undefined) params[key] = value;
    }

    for (const key of spec.lists ?? []) {
        const values = (searchParams.get(key) ?? '')
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '' && permitted(spec, key, value));
        if (values.length > 0) params[key] = values;
    }

    return params;
}

function managedKeys(spec: ListParamsSpec): Set<string> {
    return new Set(['q', 'page', 'per_page', ...(spec.strings ?? []), ...(spec.numbers ?? []), ...(spec.lists ?? [])]);
}

/**
 * Params → URL, in a fixed order (q, the page's keys, page, per_page) so
 * two equal views produce one string. Empty values and page 1 are left
 * out: the default view is the bare path.
 *
 * `keep` is the URL as it stands. Keys the list does not manage — a
 * workspace's `?tab=`, a drawer's `?open=` — are carried over untouched,
 * in their own order, ahead of the list's. Turning a page must not close
 * the tab it is on.
 */
export function writeListParams(params: ListParams, spec: ListParamsSpec, keep?: URLSearchParams): URLSearchParams {
    const out = new URLSearchParams();
    const managed = managedKeys(spec);

    keep?.forEach((value, key) => {
        if (!managed.has(key)) out.append(key, value);
    });

    const q = params.q?.trim();
    if (q) out.set('q', q);

    for (const key of [...(spec.strings ?? []), ...(spec.numbers ?? []), ...(spec.lists ?? [])]) {
        const value = params[key];
        if (value === undefined || value === '') continue;
        if (Array.isArray(value)) {
            const list = value.map((entry) => entry.trim()).filter((entry) => entry !== '');
            if (list.length > 0) out.set(key, list.join(','));
            continue;
        }
        out.set(key, String(value));
    }

    if (params.page !== undefined && params.page > 1) out.set('page', String(params.page));
    if (params.per_page !== undefined) out.set('per_page', String(params.per_page));

    return out;
}

/**
 * The params with nothing empty in them — what goes to axios and into the
 * query key, so `{ q: '' }` and `{}` are the same request.
 */
export function compactParams<T extends ListParams>(params: T): Partial<T> {
    const out: Partial<T> = {};

    for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === '') continue;
        if (Array.isArray(value) && value.length === 0) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        (out as Record<string, ListParamValue>)[key] = typeof value === 'string' ? value.trim() : value;
    }

    return out;
}

/**
 * Which of the params NARROW the list — for the "n match · Clear" line.
 * `page`, `per_page` and `sort` change order or position, never membership.
 */
export function narrowingKeys(params: ListParams): string[] {
    return Object.keys(compactParams(params)).filter((key) => !['page', 'per_page', 'sort'].includes(key));
}
