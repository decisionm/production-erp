import { describe, expect, it } from 'vitest';

/**
 * A source lint, not a render test (03-Sep-2026, reported from live: "100 per
 * page is not selected").
 *
 * antd's `pageSize` is a CONTROLLED prop. A table that passes it and offers
 * `showSizeChanger` but never updates it snaps the reader's choice straight
 * back to the fixed number — the selector looks broken because it is. A
 * client-paged table must use `defaultPageSize` and let antd own the value;
 * a server-paged one must pass `pageSize` AND an `onChange` that writes the
 * new size back (which is what `serverPagination` does).
 *
 * Three pages shipped the broken shape: the item master, the Tally vendor
 * review and client outstanding. This keeps the fourth from happening.
 *
 * Read through Vite's own glob rather than node:fs — this project ships no
 * @types/node, and a lint test is not a reason to add one.
 */
const sources = import.meta.glob('../features/**/*.tsx', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

/** Every `pagination={{ ... }}` literal in a file, as raw text. */
export function paginationBlocks(source: string): string[] {
    const blocks: string[] = [];
    const opener = 'pagination={{';
    let at = source.indexOf(opener);

    while (at !== -1) {
        // Walk braces from the literal's own `{` so a nested object or an
        // arrow body cannot end the block early.
        let depth = 0;
        let index = at + opener.length - 1;
        for (; index < source.length; index += 1) {
            if (source[index] === '{') depth += 1;
            if (source[index] === '}') {
                depth -= 1;
                if (depth === 0) break;
            }
        }
        blocks.push(source.slice(at, index + 1));
        at = source.indexOf(opener, index);
    }

    return blocks;
}

/** The broken shape: a choice offered, a size fixed, and nothing listening. */
export function offersAChoiceItCannotHonour(block: string): boolean {
    const offersChoice = /showSizeChanger:\s*true/.test(block);
    const controlsSize = /(^|[^t])\bpageSize:/.test(block);
    const listens = /onChange|onShowSizeChange/.test(block);

    return offersChoice && controlsSize && !listens;
}

describe('the page-size selector', () => {
    it('reads the pages it is meant to be linting', () => {
        // A glob that matched nothing would make the assertion below vacuous.
        expect(Object.keys(sources).length).toBeGreaterThan(50);
    });

    it('spots a size the selector cannot change', () => {
        expect(offersAChoiceItCannotHonour('pagination={{ pageSize: 25, showSizeChanger: true }}')).toBe(true);
        expect(offersAChoiceItCannotHonour('pagination={{ defaultPageSize: 25, showSizeChanger: true }}')).toBe(false);
        expect(offersAChoiceItCannotHonour('pagination={{ pageSize: p, showSizeChanger: true, onChange: turn }}')).toBe(false);
        expect(offersAChoiceItCannotHonour('pagination={{ pageSize: 20, showSizeChanger: false }}')).toBe(false);
    });

    it('is never offered beside a page size nothing updates', () => {
        const offenders = Object.entries(sources)
            .filter(([, source]) => paginationBlocks(source).some(offersAChoiceItCannotHonour))
            .map(([path]) => path);

        expect(offenders).toEqual([]);
    });
});
