import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import {
    controlFor,
    fieldLabel,
    filenameFromDisposition,
    filtersSummary,
    groupByModule,
    moduleLabel,
    optionsOf,
    refusalSentence,
    runOutcome,
    serialiseFilters,
} from './filters';
import type { ExportFilterField, ExportKind } from './types';

/**
 * The Download / Export Center's contract with GET /exports and POST
 * /exports/{kind}, pinned:
 *
 *  - the catalogue's filter schema maps to a form control the same way
 *    every time (date → DatePicker, integer/number → InputNumber, boolean →
 *    Switch, select-with-options → Select, everything else → Input);
 *  - the form's values serialise to the body the kind's OWN rules validate,
 *    with empties left out and dates as YYYY-MM-DD;
 *  - the saved file takes the server's Content-Disposition name;
 *  - a refusal is shown in the server's words, never composed here.
 */

const field = (over: Partial<ExportFilterField> = {}): ExportFilterField => ({
    name: 'date',
    type: 'date',
    required: false,
    multiple: false,
    options: null,
    ...over,
});

describe('controlFor — schema field → form control', () => {
    it('maps each schema type to its control', () => {
        expect(controlFor(field({ type: 'date' }))).toBe('date');
        expect(controlFor(field({ type: 'integer' }))).toBe('integer');
        expect(controlFor(field({ type: 'number' }))).toBe('number');
        expect(controlFor(field({ type: 'boolean' }))).toBe('boolean');
        expect(controlFor(field({ type: 'text' }))).toBe('text');
        expect(controlFor(field({ type: 'select', options: ['pending', 'synced'] }))).toBe('select');
    });

    it('renders id fields as a number box — no lookups in this phase — because the server types them integer', () => {
        expect(controlFor(field({ name: 'customer_id', type: 'integer' }))).toBe('integer');
        expect(controlFor(field({ name: 'shift_id', type: 'integer' }))).toBe('integer');
    });

    it('degrades a select without options, and any unknown type, to text — the server still validates', () => {
        expect(controlFor(field({ type: 'select', options: [] }))).toBe('text');
        expect(controlFor(field({ type: 'select', options: null }))).toBe('text');
        expect(controlFor(field({ type: 'date_range' }))).toBe('text');
        expect(controlFor(field({ type: '' }))).toBe('text');
    });
});

describe('optionsOf', () => {
    it('reads the bare values the server sends today, and an object form, into {value,label}', () => {
        expect(optionsOf(field({ options: ['pending', 'synced', 3] }))).toEqual([
            { value: 'pending', label: 'pending' },
            { value: 'synced', label: 'synced' },
            { value: 3, label: '3' },
        ]);
        expect(optionsOf(field({ options: [{ value: 'draft', label: 'Draft' }, { value: 'sent', label: '' }] }))).toEqual([
            { value: 'draft', label: 'Draft' },
            { value: 'sent', label: 'sent' },
        ]);
        expect(optionsOf(field({ options: null }))).toEqual([]);
    });
});

describe('fieldLabel', () => {
    it('humanises the rule key without changing what goes on the wire', () => {
        expect(fieldLabel('date')).toBe('Date');
        expect(fieldLabel('date_from')).toBe('Date from');
        expect(fieldLabel('shift_id')).toBe('Shift ID');
        expect(fieldLabel('work_center_id')).toBe('Work center ID');
        expect(fieldLabel('q')).toBe('Search');
    });
});

describe('serialiseFilters — form values → POST body', () => {
    const schema: ExportFilterField[] = [
        field({ name: 'date', type: 'date', required: true }),
        field({ name: 'from', type: 'date' }),
        field({ name: 'shift_id', type: 'integer' }),
        field({ name: 'status', type: 'select', multiple: true, options: ['pending', 'synced', 'failed'] }),
        field({ name: 'held', type: 'boolean' }),
        field({ name: 'q', type: 'text' }),
        field({ name: 'limit', type: 'integer' }),
    ];

    it('sends a DatePicker value as YYYY-MM-DD, never an instant', () => {
        expect(serialiseFilters(schema, { date: dayjs('2026-08-17T10:30:00+05:30') })).toEqual({ date: '2026-08-17' });
        expect(serialiseFilters(schema, { date: '2026-08-17T00:00:00.000Z' })).toEqual({ date: '2026-08-17' });
        expect(serialiseFilters(schema, { date: '2026-08-17' })).toEqual({ date: '2026-08-17' });
    });

    it('drops every empty value — undefined, null, blank strings, empty arrays, a Switch that is off', () => {
        expect(serialiseFilters(schema, {
            date: undefined,
            from: null,
            shift_id: undefined,
            status: [],
            held: false,
            q: '   ',
            limit: Number.NaN,
        })).toEqual({});
        expect(serialiseFilters(schema, undefined)).toEqual({});
        expect(serialiseFilters(schema, null)).toEqual({});
    });

    it('passes numbers, a Switch that is on, trimmed text and arrays (minus blank members) through', () => {
        expect(serialiseFilters(schema, {
            date: '2026-08-17',
            shift_id: 3,
            status: ['failed', '', 'pending'],
            held: true,
            q: '  PO-12 ',
            limit: 0,
        })).toEqual({
            date: '2026-08-17',
            shift_id: 3,
            status: ['failed', 'pending'],
            held: true,
            q: 'PO-12',
            limit: 0,
        });
    });

    it('wraps a single value of a multiple field, and reads ONLY the schema fields (an allowlist)', () => {
        expect(serialiseFilters(schema, { status: 'failed' })).toEqual({ status: ['failed'] });
        expect(serialiseFilters(schema, { date: '2026-08-17', not_a_filter: 'x', queryKey: ['a'] })).toEqual({ date: '2026-08-17' });
    });
});

describe('filenameFromDisposition — the server names the file', () => {
    it('reads the plain, quoted and RFC 5987 forms, preferring filename*', () => {
        expect(filenameFromDisposition('attachment; filename=sales_orders-20260817-1030.csv', 'x.csv'))
            .toBe('sales_orders-20260817-1030.csv');
        expect(filenameFromDisposition('attachment; filename="tally_sync_entries-20260817-1030.csv"', 'x.csv'))
            .toBe('tally_sync_entries-20260817-1030.csv');
        expect(filenameFromDisposition("attachment; filename=fallback.csv; filename*=UTF-8''r%C3%A9sum%C3%A9.csv", 'x.csv'))
            .toBe('résumé.csv');
        expect(filenameFromDisposition("attachment; filename*=UTF-8''cec-20260817-1030.csv", 'x.csv'))
            .toBe('cec-20260817-1030.csv');
    });

    it('falls back to the given name when the header is missing or carries no name, and never keeps a path', () => {
        expect(filenameFromDisposition(undefined, 'production_report.csv')).toBe('production_report.csv');
        expect(filenameFromDisposition('', 'production_report.csv')).toBe('production_report.csv');
        expect(filenameFromDisposition('inline', 'production_report.csv')).toBe('production_report.csv');
        expect(filenameFromDisposition('attachment; filename=""', 'production_report.csv')).toBe('production_report.csv');
        expect(filenameFromDisposition('attachment; filename="../../etc/passwd.csv"', 'x.csv')).toBe('passwd.csv');
    });
});

describe('refusalSentence — the server\'s words, verbatim', () => {
    it('shows the cap sentence, the blocked reason and the permission message as sent', () => {
        expect(refusalSentence(
            { message: '5,213 rows match; the cap is 5,000 — narrow the range', code: 'export_cap_exceeded', matched: 5213, cap: 5000 },
            'fallback',
        )).toBe('5,213 rows match; the cap is 5,000 — narrow the range');
        expect(refusalSentence({ message: 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED', kind: 'cec' }, 'fallback'))
            .toBe('CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED');
        expect(refusalSentence({ message: "You don't have permission to access this feature." }, 'fallback'))
            .toBe("You don't have permission to access this feature.");
    });

    it('prefers the field sentences of a validation 422 over the generic message above them', () => {
        expect(refusalSentence({
            message: 'The date field is required. (and 1 more error)',
            errors: { date: ['The date field is required.'], date_to: ['The date to field must be a date after or equal to date from.'] },
        }, 'fallback')).toBe('The date field is required. The date to field must be a date after or equal to date from.');
    });

    it('uses the fallback only when the body carries no sentence at all', () => {
        expect(refusalSentence(undefined, 'Network Error')).toBe('Network Error');
        expect(refusalSentence('<html>502</html>', 'Network Error')).toBe('Network Error');
        expect(refusalSentence({ message: '' }, 'Network Error')).toBe('Network Error');
        expect(refusalSentence({ errors: {} }, 'Network Error')).toBe('Network Error');
    });
});

describe('the catalogue on the page', () => {
    const kind = (over: Partial<ExportKind>): ExportKind => ({
        key: 'k',
        label: 'K',
        module: 'production',
        status: 'available',
        blocked_reason: null,
        row_cap: 5000,
        filters: [],
        ...over,
    });

    it('groups kinds by module in catalogue order and labels a module it does not know rather than hiding it', () => {
        const groups = groupByModule([
            kind({ key: 'shift_summary', module: 'production' }),
            kind({ key: 'tally_sync_entries', module: 'tally-sync' }),
            kind({ key: 'cec', module: 'production', status: 'blocked', blocked_reason: 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED' }),
            kind({ key: 'widgets', module: 'widgetry' }),
        ]);

        expect(groups.map((g) => [g.module, g.kinds.map((k) => k.key)])).toEqual([
            ['production', ['shift_summary', 'cec']],
            ['tally-sync', ['tally_sync_entries']],
            ['widgetry', ['widgets']],
        ]);
        expect(moduleLabel('production')).toBe('Production');
        expect(moduleLabel('tally-sync')).toBe('Tally Sync');
        expect(moduleLabel('widgetry')).toBe('Widgetry');
    });

    it('summarises a run\'s filters on one line and says what became of the run', () => {
        expect(filtersSummary({ date: '2026-08-17', shift_id: 3, status: ['failed', 'pending'], q: '' })).toBe(
            'date=2026-08-17 · shift_id=3 · status=failed,pending',
        );
        expect(filtersSummary({})).toBe('—');
        expect(filtersSummary(null)).toBe('—');

        expect(runOutcome({ completed: true, refusal_reason: null })).toEqual({ state: 'completed', text: 'Completed' });
        expect(runOutcome({ completed: false, refusal_reason: '5 rows match; the cap is 2 — narrow the range' })).toEqual({
            state: 'refused',
            text: '5 rows match; the cap is 2 — narrow the range',
        });
        expect(runOutcome({ completed: false, refusal_reason: null })).toEqual({ state: 'incomplete', text: 'Not completed' });
    });
});
