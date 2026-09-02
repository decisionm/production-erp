/**
 * THE JOURNAL REGISTER'S URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure, pinned by journalEntryList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListJournalEntriesRequest), id included. */
export const JOURNAL_ENTRY_SORT_FIELDS: readonly string[] = ['id', 'status', 'entry_date', 'reference'];
/** JournalEntryService's order when no sort is asked for: newest first. */
export const JOURNAL_ENTRY_DEFAULT_SORT = '-id';

export const JOURNAL_ENTRY_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: JOURNAL_ENTRY_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type JournalEntryListParams = ListParams & { sort?: string };

export interface JournalEntryListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function journalEntryServerFilters(params: JournalEntryListParams): JournalEntryListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['finance', 'journal-entries'] prefix every entry mutation already invalidates. */
export function journalEntriesQueryKey(filters: JournalEntryListFilters) {
    return ['finance', 'journal-entries', 'list', filters] as const;
}
