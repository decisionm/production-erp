import { describe, expect, it } from 'vitest';

/**
 * WHAT THE SHIFT FLOOR IS ALLOWED TO SAY.
 *
 * DEC-20260817-001 records the factory's logical inventory locations as Raw
 * Material Store -> Production/WIP -> Finished Goods Store, and says in as many
 * words: **there is no Day Bin**. PR #195 stopped the location being OFFERED,
 * but the words survived in this page's copy — links and sentences still named
 * a place the factory does not have, while the page they linked to had already
 * been renamed "Common resin input".
 *
 * THIS GUARD IS DELIBERATELY NOT "FIND THE USER-VISIBLE STRINGS".
 *
 * Its first version tried exactly that — extract quoted literals and text
 * between tags, then assert none of them says "day bin" — and it was vacuous
 * for precisely the content it existed to protect. Restoring two of the real
 * sentences this PR fixed left it green:
 *
 *   "The day bin has no {material...} recorded — load it in"
 *   "No factory day bin chosen yet, so each line still asks..."
 *
 * Neither is a quoted literal (JSX prose is not a string), and neither survives
 * a `>([^<>{}\n]+)<` extractor: Prettier wraps prose onto its own lines, and
 * almost every sentence on this page carries a `{expr}` or a `{' '}`. The
 * extractor reached link labels and missed paragraphs.
 *
 * So the test is inverted. It scans the WHOLE comment-stripped source and
 * allow-lists the identifiers that are legitimately still named `day-bin` —
 * the route, the query keys, the API functions, the form field. Anything else
 * matching the phrase fails. That turns the failure mode from silent-miss into
 * noisy-and-fixable: a new legitimate identifier makes this test fail once and
 * gets added to the list, while a sentence can never slip through unnoticed.
 *
 * Read through Vite's own `?raw` import rather than `node:fs`: this project
 * ships no Node type definitions, and a UI-only change is not the place to add
 * a dependency to satisfy one test.
 */
const sources = import.meta.glob('./pages/ShiftProductionEntryPage.tsx', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;
const source = Object.values(sources)[0];

/** Source with every comment removed — a comment is not something a user reads. */
const withoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^[ \t]*\/\/.*$/gm, '');

/**
 * The identifiers a machine reads, which legitimately keep the old name until
 * the backend renames them: the route, the query keys, the API function names,
 * the completion form's field, the resource types. Renaming these is a
 * migration with API consequences, and is NOT what this guards.
 *
 * Every entry here is a deliberate exception. Adding one is a decision; the
 * point of the list is that it has to be made in the open.
 */
const ALLOWED_IDENTIFIERS = [
    'getEntryDayBinSummary',
    'getFactoryDayBin',
    'loadBagToFactoryDayBin',
    'EntryDayBinMaterialSummary',
    'applyDayBinConsumption',
    'factoryDayBin',
    'dayBinWarehouseId',
    'dayBinLines',
    'dayBinByItem',
    'entryDayBin',
    'factory-day-bin',
    'entry-day-bin',
    'day-bin',
    'closing_day_bin',
    'DayBinSummary',
    'DayBinLine',
    'dayBinHint',
    'dayBinBalances',
    'dayBinKgFor',
    'day_bin',
    'day.?bin warehouse',
];

describe('Shift Floor copy — DEC-20260817-001: there is no Day Bin', () => {
    const DAY_BIN = /day[\s_-]*bin/gi;

    it('names no Day Bin anywhere except the machine identifiers on the allow-list', () => {
        const offending: string[] = [];

        for (const match of withoutComments.matchAll(DAY_BIN)) {
            const index = match.index ?? 0;
            // The surrounding token/phrase, so an identifier can be recognised
            // and a sentence cannot hide inside one.
            const context = withoutComments.slice(Math.max(0, index - 40), index + 40);
            const allowed = ALLOWED_IDENTIFIERS.some((identifier) => context.includes(identifier));
            if (!allowed) offending.push(context.replace(/\s+/g, ' ').trim());
        }

        expect(offending).toEqual([]);
    });

    /**
     * THE GUARD MUST FAIL ON THE THING IT GUARDS. A test that cannot fail is
     * not a test — this is the exact regression the first version missed, run
     * against a synthetic copy of the source rather than against the file, so
     * it proves the DETECTION rather than the current state of the page.
     */
    it('catches a Day Bin sentence restored into JSX prose', () => {
        const reverted = `
            <Typography.Text type="secondary">
                The day bin has no {material ? material.name : 'stock'} recorded — load it in{' '}
            </Typography.Text>
        `;
        const hits = [...reverted.matchAll(DAY_BIN)].filter((match) => {
            const context = reverted.slice(Math.max(0, (match.index ?? 0) - 40), (match.index ?? 0) + 40);
            return !ALLOWED_IDENTIFIERS.some((identifier) => context.includes(identifier));
        });
        expect(hits.length).toBeGreaterThan(0);
    });

    it('does not fire on a legitimate machine identifier', () => {
        const legitimate = `queryKey: ['production', 'factory-day-bin'], queryFn: getFactoryDayBin,`;
        const hits = [...legitimate.matchAll(DAY_BIN)].filter((match) => {
            const context = legitimate.slice(Math.max(0, (match.index ?? 0) - 40), (match.index ?? 0) + 40);
            return !ALLOWED_IDENTIFIERS.some((identifier) => context.includes(identifier));
        });
        expect(hits).toEqual([]);
    });
});
