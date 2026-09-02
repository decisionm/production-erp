import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { JOURNAL_ENTRY_DEFAULT_SORT, JOURNAL_ENTRY_LIST_SPEC, journalEntryServerFilters } from './journalEntryList';

describe('the journal register list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=memo'), JOURNAL_ENTRY_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(journalEntryServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=entry_date&page=2'), JOURNAL_ENTRY_LIST_SPEC);

        expect(journalEntryServerFilters(params)).toEqual({ sort: 'entry_date', page: 2 });
    });

    it('opens newest first, the service default, with no sort on the URL', () => {
        expect(JOURNAL_ENTRY_DEFAULT_SORT).toBe('-id');
        expect(journalEntryServerFilters({})).toEqual({});
        expect(columnSortOrder('id', undefined, JOURNAL_ENTRY_DEFAULT_SORT)).toBe('descend');
    });
});
