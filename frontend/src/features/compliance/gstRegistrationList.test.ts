import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { GST_REGISTRATION_DEFAULT_SORT, GST_REGISTRATION_LIST_SPEC, gstRegistrationServerFilters } from './gstRegistrationList';

describe('the GST registration list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=is_primary'), GST_REGISTRATION_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(gstRegistrationServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=state_name&page=2'), GST_REGISTRATION_LIST_SPEC);

        expect(gstRegistrationServerFilters(params)).toEqual({ sort: 'state_name', page: 2 });
    });

    it('opens in the service default (primary first, then state) with no sort on the URL and no arrow lit', () => {
        expect(GST_REGISTRATION_DEFAULT_SORT).toBeUndefined();
        expect(gstRegistrationServerFilters({})).toEqual({});
        expect(columnSortOrder('state_name', undefined, GST_REGISTRATION_DEFAULT_SORT)).toBeNull();
    });
});
