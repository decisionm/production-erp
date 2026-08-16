import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import type { SalesDocumentTarget } from './SalesDocumentDrawer';
import { documentNumber, filtersFromSearchParams, parseDocumentRef, searchParamsFromFilters } from './filters';
import type { SalesDocumentKind, SalesListFilters } from './types';

/**
 * The list page's state IS its URL: the filters, the page, and which
 * document's drawer is open (`?open=SO-12`). A pasted link reopens the same
 * view; Back closes the drawer. Nothing here is kept in component state,
 * so the three list pages cannot drift from one another in how they read
 * or write it.
 *
 *  - `filters`   what the bar shows and the list asks the server for
 *  - `setFilters` rewrites the URL (replace, so the Back button walks pages,
 *                not keystrokes) and resets to page 1, because page 3 of a
 *                different query is a page nobody asked for; `setPage`
 *                turns the page and keeps everything else
 *  - `target`    the drawer's document, from `?open=` — a bare number is
 *                this page's own kind; "INV-3" on the orders page opens the
 *                invoice, since the drawer is kind-agnostic
 */
export function useSalesListParams(kind: SalesDocumentKind) {
    const [searchParams, setSearchParams] = useSearchParams();

    const filters = useMemo(() => filtersFromSearchParams(kind, searchParams), [kind, searchParams]);
    const target = useMemo<SalesDocumentTarget | null>(
        () => parseDocumentRef(searchParams.get('open'), kind),
        [kind, searchParams],
    );

    const write = useCallback(
        (nextFilters: SalesListFilters, nextTarget: SalesDocumentTarget | null, replace: boolean) => {
            const params = searchParamsFromFilters(kind, nextFilters);
            if (nextTarget) params.set('open', documentNumber(nextTarget.kind, nextTarget.id));
            setSearchParams(params, { replace });
        },
        [kind, setSearchParams],
    );

    /** Change what the list asks for — and go back to page 1 of it. */
    const setFilters = useCallback(
        (update: SalesListFilters | ((prev: SalesListFilters) => SalesListFilters)) => {
            const next = typeof update === 'function' ? update(filters) : update;
            write({ ...next, page: undefined }, target, true);
        },
        [filters, target, write],
    );

    /** Turn the page (or resize it) without touching the filters. */
    const setPage = useCallback(
        (page: number, perPage?: number) => write({ ...filters, page, per_page: perPage ?? filters.per_page }, target, true),
        [filters, target, write],
    );

    const openTarget = useCallback(
        (next: SalesDocumentTarget) => write(filters, next, false),
        [filters, write],
    );

    const closeTarget = useCallback(() => write(filters, null, true), [filters, write]);

    return { filters, setFilters, setPage, target, openTarget, closeTarget };
}
