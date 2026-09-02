import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { type ListParams, type ListParamsSpec, readListParams, writeListParams } from './listParams';

/**
 * A list page's search, filters, page and page size, kept in the URL.
 *
 *  - `params`     what the toolbar shows and the list asks the server for
 *  - `setParams`  change what the list asks for — and go back to page 1
 *                 of it, because page 3 of a different query is a page
 *                 nobody asked for. Writes with `replace`, so Back walks
 *                 pages the user turned, not keystrokes.
 *  - `setPage`    turn (or resize) the page and keep everything else
 *  - `reset`      the bare path: no search, no filters, page 1
 *
 * `spec` must be a module-level constant: it is a dependency of every
 * memo here, and an inline literal would rebuild them on each render.
 */
export function useListParams<P extends ListParams = ListParams>(spec: ListParamsSpec) {
    const [searchParams, setSearchParams] = useSearchParams();

    const params = useMemo(() => readListParams(searchParams, spec) as P, [searchParams, spec]);

    const write = useCallback(
        (next: ListParams) => setSearchParams(writeListParams(next, spec, searchParams), { replace: true }),
        [searchParams, setSearchParams, spec],
    );

    const setParams = useCallback(
        (patch: Partial<P> | ((prev: P) => Partial<P>)) => {
            const next = typeof patch === 'function' ? patch(params) : patch;
            write({ ...params, ...next, page: undefined });
        },
        [params, write],
    );

    const setPage = useCallback(
        (page: number, perPage?: number) => write({ ...params, page, per_page: perPage ?? params.per_page }),
        [params, write],
    );

    const reset = useCallback(() => write({}), [write]);

    return { params, setParams, setPage, reset };
}
