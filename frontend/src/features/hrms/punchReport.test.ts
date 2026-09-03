import { describe, expect, it } from 'vitest';
import fixture from './punchReport.fixture.json';
import { parseClock, parseDuration, parsePunchWorkbook, punchImportPayload } from './punchReport';

/**
 * The fixture is four employee blocks of the real July 2026 Pooja report,
 * copied cell for cell with the names and IDs replaced (build-punch-fixture.py).
 * Every shape the file actually has is in it: FD / HD / Absent / Week
 * Off / "-" statuses, a punch-in with no punch-out, an "Absent" day that
 * still carries a punch, and a Total Hrs of "8h " with no minutes.
 */
const rows = fixture as unknown[][];

describe('parseClock', () => {
    it("reads the report's 12-hour clock into HH:MM", () => {
        expect(parseClock('10:10 AM')).toBe('10:10');
        expect(parseClock('08:20 PM')).toBe('20:20');
        expect(parseClock('12:05 AM')).toBe('00:05');
        expect(parseClock('12:30 PM')).toBe('12:30');
        expect(parseClock('09:58')).toBe('09:58');
    });

    it('is null for a dash or a blank, undefined for nonsense', () => {
        expect(parseClock('-')).toBeNull();
        expect(parseClock('')).toBeNull();
        expect(parseClock(null)).toBeNull();
        expect(parseClock('ten')).toBeUndefined();
        expect(parseClock('25:00')).toBeUndefined();
    });
});

describe('parseDuration', () => {
    it('reads hours and minutes into minutes', () => {
        expect(parseDuration('01h 47m')).toBe(107);
        expect(parseDuration('54m')).toBe(54);
        expect(parseDuration('8h ')).toBe(480);
        expect(parseDuration('10h 9m')).toBe(609);
        expect(parseDuration('-')).toBe(0);
        expect(parseDuration(null)).toBe(0);
        expect(parseDuration('soon')).toBeUndefined();
    });
});

describe('parsePunchWorkbook', () => {
    const parsed = parsePunchWorkbook(rows);

    it('finds every block by its From: row and reads the period once', () => {
        expect(parsed.warnings).toEqual([]);
        expect(parsed.period).toEqual({ from: '2026-07-01', to: '2026-07-31' });
        expect(parsed.employees.map((e) => e.employee_code)).toEqual(['TST-01', 'TST-02', 'TST-03', 'TST-04']);
        expect(parsed.employees.every((e) => e.days.length === 31)).toBe(true);
    });

    it('reads the header fields', () => {
        const first = parsed.employees[0];
        expect(first.name).toBe('EMPLOYEE 01');
        expect(first.department).toBe('Human Resource');
        expect(first.designation).toBe('HR');
    });

    it('reads a day column as one day with its punches and durations', () => {
        const first = parsed.employees[0];
        expect(first.days[0]).toEqual({
            date: '2026-07-01',
            status: 'Absent',
            first_in: null,
            last_out: null,
            ot_minutes: 0,
            late_minutes: 0,
            early_minutes: 0,
            worked_minutes: 0,
        });
        expect(first.days[1]).toEqual({
            date: '2026-07-02',
            status: 'FD',
            first_in: '10:10',
            last_out: '20:20',
            ot_minutes: 129,
            late_minutes: 40,
            early_minutes: 0,
            worked_minutes: 609,
        });
        expect(first.days[4].status).toBe('Week Off');
        expect(first.days[30].date).toBe('2026-07-31');
    });

    it('keeps a half day, a punch-in with no punch-out, and a dash status as the file has them', () => {
        const second = parsed.employees[1];
        expect(second.days[1]).toMatchObject({ status: 'HD', first_in: '06:25', last_out: '14:13' });
        expect(second.days[6]).toMatchObject({ status: 'Absent', first_in: '22:20', last_out: null });

        const fourth = parsed.employees[3];
        expect(fourth.days[0]).toMatchObject({ status: '-', first_in: null, last_out: null });
        expect(fourth.days[3]).toMatchObject({ status: 'FD', first_in: '18:35', last_out: null, worked_minutes: 480 });
    });

    it('turns an unreadable block into a warning and keeps the rest', () => {
        const broken = rows.map((row) => [...row]);
        // Second block: blank its Status row label so the block is missing a row.
        broken[17][0] = 'Stat';
        // Third block: an unreadable time.
        broken[31][5] = 'noon';

        const result = parsePunchWorkbook(broken);
        expect(result.employees.map((e) => e.employee_code)).toEqual(['TST-01', 'TST-04']);
        expect(result.warnings).toEqual(['TST-02: rows missing (Status).', 'TST-03: day 5: time "noon" / "04:21 PM" not readable; block skipped.']);
    });

    it('skips a block whose period differs and says so', () => {
        const mixed = rows.map((row) => [...row]);
        mixed[13][0] = String(mixed[13][0]).replace('To: 2026-07-31', 'To: 2026-08-31');

        const result = parsePunchWorkbook(mixed);
        expect(result.employees).toHaveLength(3);
        expect(result.warnings).toEqual(['TST-02: period 2026-07-01 to 2026-08-31 differs from 2026-07-01 to 2026-07-31; block skipped.']);
    });

    it('an empty sheet is a warning, not a crash', () => {
        expect(parsePunchWorkbook([])).toEqual({ period: null, employees: [], warnings: ['No employee blocks found (no "From:" row).'] });
        expect(parsePunchWorkbook([[null, null], ['hello']]).employees).toEqual([]);
    });
});

describe('punchImportPayload', () => {
    it('is the POST body', () => {
        const payload = punchImportPayload(parsePunchWorkbook(rows), 'july.xlsx');
        expect(payload.period_from).toBe('2026-07-01');
        expect(payload.period_to).toBe('2026-07-31');
        expect(payload.source).toBe('pooja');
        expect(payload.file_name).toBe('july.xlsx');
        expect(payload.employees).toHaveLength(4);
    });

    it('refuses a parse with no period', () => {
        expect(() => punchImportPayload({ period: null, employees: [], warnings: [] }, 'x.xlsx')).toThrow('No period');
    });
});
