import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { filtersFromSearchParams, searchParamsFromFilters } from './purchaseOrders';
import type { PurchaseOrderListFilters } from './types';

/**
 * The Purchase Orders page's state IS its URL (the Sales list pages'
 * pattern, useSalesListParams): the server-side filters, the page, and
 * which drawer is open — `?open=7` the order's detail, `?trace=7` its
 * trace. A pasted link reopens the same view; Back closes a drawer.
 *
 * `?po=7` is the OLD deep link every goods receipt, movement and lot
 * screen still writes ("the order this was received against"); it is read
 * as `?open=7` so those links keep working, and `?po=7&view=trace` opens
 * the trace instead. Nothing here is component state, so the list, the
 * detail drawer and the trace drawer cannot disagree about which order is
 * being looked at.
 *
 *  - `filters`     what the bar shows and the list asks the server for
 *  - `setFilters`  rewrites the URL (replace, so Back walks pages, not
 *                  keystrokes) and resets to page 1
 *  - `setPage`     turns the page and keeps everything else
 *  - `openId`      the detail drawer's order, `traceId` the trace drawer's
 */
export function usePurchaseOrderListParams() {
    const [searchParams, setSearchParams] = useSearchParams();

    const filters = useMemo(() => filtersFromSearchParams(searchParams), [searchParams]);

    const legacy = positiveInt(searchParams.get('po'));
    const legacyView = searchParams.get('view');
    const openId = positiveInt(searchParams.get('open')) ?? (legacyView === 'trace' ? null : legacy);
    const traceId = positiveInt(searchParams.get('trace')) ?? (legacyView === 'trace' ? legacy : null);

    const write = useCallback(
        (nextFilters: PurchaseOrderListFilters, nextOpen: number | null, nextTrace: number | null, replace: boolean) => {
            const params = searchParamsFromFilters(nextFilters);
            if (nextOpen !== null) params.set('open', String(nextOpen));
            if (nextTrace !== null) params.set('trace', String(nextTrace));
            setSearchParams(params, { replace });
        },
        [setSearchParams],
    );

    /** Change what the list asks for — and go back to page 1 of it. */
    const setFilters = useCallback(
        (update: PurchaseOrderListFilters | ((prev: PurchaseOrderListFilters) => PurchaseOrderListFilters)) => {
            const next = typeof update === 'function' ? update(filters) : update;
            write({ ...next, page: undefined }, openId, traceId, true);
        },
        [filters, openId, traceId, write],
    );

    /** Turn the page (or resize it) without touching the filters. */
    const setPage = useCallback(
        (page: number, perPage?: number) => write({ ...filters, page, per_page: perPage ?? filters.per_page }, openId, traceId, true),
        [filters, openId, traceId, write],
    );

    const openDetail = useCallback((id: number) => write(filters, id, null, false), [filters, write]);
    const openTrace = useCallback((id: number) => write(filters, null, id, false), [filters, write]);
    const closeDrawers = useCallback(() => write(filters, null, null, true), [filters, write]);

    return { filters, setFilters, setPage, openId, traceId, openDetail, openTrace, closeDrawers };
}

function positiveInt(value: string | null): number | null {
    if (value === null || !/^\d+$/.test(value.trim())) return null;
    const number = Number(value);

    return Number.isInteger(number) && number > 0 ? number : null;
}
