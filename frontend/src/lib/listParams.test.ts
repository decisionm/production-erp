import { describe, expect, it } from 'vitest';
import { compactParams, narrowingKeys, readListParams, writeListParams } from './listParams';

const spec = {
    strings: ['status', 'kind'],
    numbers: ['item_id'],
    lists: ['states'],
    allowed: { status: ['open', 'closed'], states: ['a', 'b'] },
};

describe('readListParams', () => {
    it('reads the shared keys and the page’s own keys, typed', () => {
        const params = readListParams(new URLSearchParams('q=%20resin%20&page=3&per_page=50&status=open&item_id=12&states=a,b'), spec);

        expect(params).toEqual({ q: 'resin', page: 3, per_page: 50, status: 'open', item_id: 12, states: ['a', 'b'] });
    });

    it('drops what the spec does not name, what is not allowed, and what is not a number', () => {
        const params = readListParams(
            new URLSearchParams('stray=1&status=bogus&item_id=abc&states=a,zzz,%20,b&page=0&per_page=500'),
            spec,
        );

        expect(params).toEqual({ states: ['a', 'b'] });
    });

    it('treats page 1 and an empty search as the default view', () => {
        expect(readListParams(new URLSearchParams('page=1&q=%20%20'), spec)).toEqual({});
    });
});

describe('writeListParams', () => {
    it('writes a fixed order and leaves out page 1 and empties', () => {
        const out = writeListParams(
            { q: ' bottle ', status: 'open', item_id: 7, states: ['b', ''], page: 1, per_page: 50, kind: '' },
            spec,
        );

        expect(out.toString()).toBe('q=bottle&status=open&item_id=7&states=b&per_page=50');
    });

    it('carries a key it does not manage — the workspace tab, an open drawer — through a page turn', () => {
        const out = writeListParams({ page: 2, status: 'open' }, spec, new URLSearchParams('tab=issues&open=7&status=stale&q=old'));

        expect(out.toString()).toBe('tab=issues&open=7&status=open&page=2');
    });

    it('round-trips through read', () => {
        const params = { q: 'cap', status: 'closed', page: 2, states: ['a'] };
        const out = writeListParams(params, spec);

        expect(readListParams(out, spec)).toEqual(params);
    });
});

describe('compactParams and narrowingKeys', () => {
    it('makes an empty search and no search the same request', () => {
        expect(compactParams({ q: '', status: undefined, states: [], page: 2 })).toEqual({ page: 2 });
        expect(compactParams({ q: ' x ' })).toEqual({ q: 'x' });
    });

    it('counts only the keys that narrow membership', () => {
        expect(narrowingKeys({ q: 'x', status: 'open', page: 3, per_page: 50, sort: '-id' })).toEqual(['q', 'status']);
        expect(narrowingKeys({ page: 3 })).toEqual([]);
    });
});
