import { describe, expect, it } from 'vitest';

/**
 * WHAT THE SHIFT FLOOR IS ALLOWED TO SAY.
 *
 * DEC-20260817-001 records the factory's logical inventory locations as Raw
 * Material Store -> Production/WIP -> Finished Goods Store, and says in as many
 * words: **there is no Day Bin**. PR #195 stopped the location being OFFERED,
 * but the words survived in this page's copy — four links and four sentences
 * still named a place the factory does not have, while the page they linked to
 * had already been renamed "Common resin input".
 *
 * This pins the copy, not the code. `factory-day-bin` remains a route, a query
 * key and a service name — renaming those is a refactor with migration and API
 * consequences and is deliberately NOT what this guards. What it guards is that
 * none of it reaches a supervisor's eyes.
 *
 * Read through Vite's own `?raw` import rather than `node:fs`: this project
 * ships no Node type definitions, and a UI-only change is not the place to add
 * a dependency to satisfy one test.
 *
 * Read off the source rather than a render because the strings live behind
 * modals, drawers and flags that no single render reaches — a DOM assertion
 * would pass by never opening the drawer that says it.
 */
const sources = import.meta.glob('./pages/ShiftProductionEntryPage.tsx', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;
const source = Object.values(sources)[0];

/** Source with every comment removed — a comment is not user-visible copy. */
const withoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^[ \t]*\/\/.*$/gm, '');

/**
 * An identifier the machine reads, not a phrase a person does: a route path, a
 * query key, a form field name. They are exactly the strings that are all
 * lower-case with no spaces — `factory-day-bin`, `closing_day_bin`,
 * `/production/day-bin` — and they are legitimately allowed to keep the old
 * name until the backend does.
 */
const isMachineIdentifier = (value: string) => !/\s/.test(value) && value === value.toLowerCase();

describe('Shift Floor copy — DEC-20260817-001: there is no Day Bin', () => {
    const dayBin = /day[\s-]*bin/i;

    it('names no Day Bin in any string a supervisor can read', () => {
        const literals = [...withoutComments.matchAll(/'([^'\\\n]+)'|"([^"\\\n]+)"|`([^`]+)`/g)]
            .map((match) => match[1] ?? match[2] ?? match[3])
            .filter((value) => dayBin.test(value))
            .filter((value) => !isMachineIdentifier(value));

        expect(literals).toEqual([]);
    });

    it('names no Day Bin in any text rendered between tags', () => {
        // TSX makes `>` and `<` ambiguous — generics and comparisons look like
        // tags to a regex. Prose is what is wanted, so a candidate must sit on
        // one line and carry none of the punctuation that marks it as code.
        const looksLikeCode = /[=;&|]/;
        const text = [...withoutComments.matchAll(/>([^<>{}\n]+)</g)]
            .map((match) => match[1].trim())
            .filter((value) => value !== '' && !looksLikeCode.test(value))
            .filter((value) => dayBin.test(value));

        expect(text).toEqual([]);
    });

    it('still recognises the wording it is guarding against', () => {
        // A guard that cannot fail is not a guard. If the patterns above ever
        // stop matching the phrase itself, these assertions fail loudly rather
        // than the suite going quietly green on a broken regex.
        expect(dayBin.test('Day Bin (factory)')).toBe(true);
        expect(dayBin.test('the day bin')).toBe(true);
        expect(isMachineIdentifier('factory-day-bin')).toBe(true);
        expect(isMachineIdentifier('The day bin has no stock')).toBe(false);
        expect(/>([^<>{}\n]+)</.exec('<Link>Day Bin (factory)</Link>')?.[1]).toBe('Day Bin (factory)');
    });
});
