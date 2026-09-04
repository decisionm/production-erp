/**
 * THE POOJA PUNCH REPORT, PARSED IN THE BROWSER (03-Sep design, Track 2).
 *
 * The "Employee day wise master report" is employee blocks. A block:
 *
 *   row 0   "From: 2026-07-01 \nTo: 2026-07-31 \nEmployee Name: X \n
 *            Department: Y \nDesignation: Z \nEmployee ID: SPP-nn"
 *   row 1   the summary line (ignored — the days are the record)
 *   row 2   blank
 *   row 3   "Day"       | "1\n(Wednesday)" | "2\n(Thursday)" | …
 *   row 4   "Status"    | FD | HD | Absent | Week Off | -
 *   row 5   "First In"  | "10:10 AM" | -
 *   row 6   "Last Out"  | "08:20 PM" | -
 *   row 7   "Total OT"  | "01h 47m" | "54m" | -
 *   row 8   "Late In"
 *   row 9   "Early Out"
 *   row 10  "Total Hrs" | "10h 9m" | "8h " | -
 *   then blank rows until the next "From:" row.
 *
 * THE MONTH CAN WRAP. July 2026 printed all 31 days in that one band.
 * August 2026 printed 1–15 there and 16–31 in a CONTINUATION band below
 * it which carries no labels at all: its day 16 sits in column A, where
 * "Day" would be, so the whole band is shifted one column left and the
 * seven value rows follow it in the fixed ROW_LABELS order. A band is
 * therefore (day row, value rows, first day column) and a block has one
 * or more of them, detected per block — nothing promises a later file
 * picks one layout and keeps it.
 *
 * THE WORKBOOK CAN HAVE MANY SHEETS. July was a single "employee-master";
 * August split the factory across Staff / Ladies / uncover / Gents. Every
 * sheet is parsed and the results merged, so a sheet is never skipped in
 * silence.
 *
 * Both of those shapes changed under us without warning and the parser
 * still reported success — on 12% of the file. So it now also COUNTS:
 * an employee short of the period's days is a warning, not a clean read.
 *
 * Blocks are found by their "From:" row, never by a fixed stride, so an
 * extra blank line cannot shift every later employee. A block that cannot
 * be read becomes a warning and the rest of the file still parses.
 *
 * Pure — no SheetJS here — so the vitest fixtures (the real July and
 * August rows, names replaced) pin it. `readPunchWorkbook` is the one
 * function that touches the file, and the only place `xlsx` is imported.
 *
 * Output times are wall-clock `HH:MM` (24-hour), exactly as the report
 * printed them in IST; the server builds the instant. Durations are whole
 * minutes.
 */

export interface PunchDay {
    date: string;
    status: string;
    first_in: string | null;
    last_out: string | null;
    ot_minutes: number;
    late_minutes: number;
    early_minutes: number;
    worked_minutes: number;
}

export interface PunchEmployee {
    employee_code: string;
    name: string;
    department: string | null;
    designation: string | null;
    days: PunchDay[];
}

export interface PunchWorkbook {
    period: { from: string; to: string } | null;
    employees: PunchEmployee[];
    warnings: string[];
}

/** The seven value rows, in the order a continuation band prints them. */
const VALUE_LABELS = ['Status', 'First In', 'Last Out', 'Total OT', 'Late In', 'Early Out', 'Total Hrs'] as const;
type ValueLabel = (typeof VALUE_LABELS)[number];

const ROW_LABELS = ['Day', 'Status', 'First In', 'Last Out', 'Total OT', 'Late In', 'Early Out', 'Total Hrs'] as const;
type RowLabel = (typeof ROW_LABELS)[number];

/** One run of day columns: its day row, its seven value rows, and where day one sits. */
interface Band {
    dayRow: unknown[];
    values: Record<ValueLabel, unknown[]>;
    firstColumn: number;
}

function text(value: unknown): string {
    if (value === null || value === undefined) return '';
    return String(value).trim();
}

function blank(value: unknown): boolean {
    const s = text(value);
    return s === '' || s === '-';
}

/** "10:10 AM" / "08:20 PM" / "10:10" → "HH:MM"; "-" or blank → null; anything else → undefined (unreadable). */
export function parseClock(value: unknown): string | null | undefined {
    if (blank(value)) return null;
    const match = /^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i.exec(text(value));
    if (!match) return undefined;

    let hours = Number(match[1]);
    const minutes = Number(match[2]);
    const meridiem = match[3]?.toUpperCase();
    if (minutes > 59 || hours > 23 || (meridiem && hours > 12)) return undefined;
    if (meridiem === 'PM' && hours < 12) hours += 12;
    if (meridiem === 'AM' && hours === 12) hours = 0;

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

/** "01h 47m" → 107, "54m" → 54, "8h " → 480, "-" → 0; unreadable → undefined. */
export function parseDuration(value: unknown): number | undefined {
    if (blank(value)) return 0;
    const match = /^(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?$/i.exec(text(value));
    if (!match || (match[1] === undefined && match[2] === undefined)) return undefined;

    return Number(match[1] ?? 0) * 60 + Number(match[2] ?? 0);
}

function headerField(header: string, label: string): string | null {
    const match = new RegExp(`${label}:\\s*(.*)`).exec(header);
    if (!match) return null;
    const value = match[1].trim();

    return value === '' ? null : value;
}

function isBlockStart(row: unknown[] | undefined): boolean {
    return row !== undefined && text(row[0]).startsWith('From:');
}

/** "1\n(Wednesday)" → 1; anything else → undefined. */
function dayNumber(value: unknown): number | undefined {
    const match = /^(\d{1,2})\b/.exec(text(value));

    return match ? Number(match[1]) : undefined;
}

/**
 * The first cell of a continuation band's day row — "16\n(Sunday)".
 * The weekday bracket is the whole test: column A of a continuation band
 * also holds values like "5h 13m" and "03h 20m", which start with digits
 * too and must not be mistaken for the start of a band.
 */
function isContinuationDay(value: unknown): boolean {
    return /^\d{1,2}\s*\(/.test(text(value).replace(/\s+/g, ' '));
}

function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

function daysInPeriod(from: string, to: string): number {
    return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A block's bands: the labelled one, then every continuation below it, in
 * printed order. `missing` names the labelled rows that were not there.
 */
function readBands(rows: unknown[][], start: number, end: number): { bands: Band[]; missing: RowLabel[]; short: number[] } {
    const labelled: Partial<Record<RowLabel, unknown[]>> = {};
    const continuations: Band[] = [];
    const short: number[] = [];

    for (let index = start + 1; index < end; index += 1) {
        const row = rows[index];
        if (row === undefined) continue;

        const rowLabel = text(row[0]) as RowLabel;
        if ((ROW_LABELS as readonly string[]).includes(rowLabel)) {
            if (labelled[rowLabel] === undefined) labelled[rowLabel] = row;
            continue;
        }

        if (!isContinuationDay(row[0])) continue;

        const values = {} as Record<ValueLabel, unknown[]>;
        const last = index + VALUE_LABELS.length;
        if (last >= end || rows[last] === undefined) {
            short.push(index + 1);
            continue;
        }
        VALUE_LABELS.forEach((label, offset) => {
            values[label] = rows[index + 1 + offset]!;
        });
        continuations.push({ dayRow: row, values, firstColumn: 0 });
    }

    const missing = ROW_LABELS.filter((label) => labelled[label] === undefined);
    if (missing.length > 0) return { bands: [], missing, short };

    const values = {} as Record<ValueLabel, unknown[]>;
    VALUE_LABELS.forEach((label) => {
        values[label] = labelled[label]!;
    });

    return { bands: [{ dayRow: labelled.Day!, values, firstColumn: 1 }, ...continuations], missing, short };
}

/**
 * One sheet as SheetJS's `sheet_to_json(ws, { header: 1 })` gives it — an
 * array of row arrays — into employees and their days.
 */
export function parsePunchWorkbook(rows: unknown[][]): PunchWorkbook {
    const employees: PunchEmployee[] = [];
    const warnings: string[] = [];
    let period: PunchWorkbook['period'] = null;

    const starts = rows.map((row, index) => (isBlockStart(row) ? index : -1)).filter((index) => index >= 0);
    if (starts.length === 0) {
        warnings.push('No employee blocks found (no "From:" row).');
    }

    starts.forEach((start, n) => {
        const end = n + 1 < starts.length ? starts[n + 1] : rows.length;
        const header = text(rows[start][0]);
        const code = headerField(header, 'Employee ID');
        const name = headerField(header, 'Employee Name');
        const label = code ?? name ?? `block at row ${start + 1}`;

        const from = headerField(header, 'From');
        const to = headerField(header, 'To');
        if (!from || !to || !ISO_DATE.test(from) || !ISO_DATE.test(to)) {
            warnings.push(`${label}: period not readable.`);
            return;
        }
        if (period === null) {
            period = { from, to };
        } else if (period.from !== from || period.to !== to) {
            warnings.push(`${label}: period ${from} to ${to} differs from ${period.from} to ${period.to}; block skipped.`);
            return;
        }

        if (!code || !name) {
            warnings.push(`${label}: employee ID or name missing.`);
            return;
        }

        const { bands, missing, short } = readBands(rows, start, end);
        if (missing.length > 0) {
            warnings.push(`${code}: rows missing (${missing.join(', ')}).`);
            return;
        }
        short.forEach((row) => warnings.push(`${code}: the day band at row ${row} is cut short; its days are not read.`));

        const days: PunchDay[] = [];
        const seen = new Set<number>();
        let broken: string | null = null;

        for (const band of bands) {
            for (let column = band.firstColumn; column < band.dayRow.length; column += 1) {
                const day = dayNumber(band.dayRow[column]);
                if (day === undefined) {
                    if (blank(band.dayRow[column])) continue;
                    broken = `day column ${column} reads "${text(band.dayRow[column])}"`;
                    break;
                }
                const date = addDays(from, day - 1);
                if (date > to) {
                    broken = `day ${day} falls outside ${from} to ${to}`;
                    break;
                }
                if (seen.has(day)) {
                    broken = `day ${day} is printed twice`;
                    break;
                }
                seen.add(day);

                const firstIn = parseClock(band.values['First In'][column]);
                const lastOut = parseClock(band.values['Last Out'][column]);
                const ot = parseDuration(band.values['Total OT'][column]);
                const late = parseDuration(band.values['Late In'][column]);
                const early = parseDuration(band.values['Early Out'][column]);
                const worked = parseDuration(band.values['Total Hrs'][column]);
                if (firstIn === undefined || lastOut === undefined) {
                    broken = `day ${day}: time "${text(band.values['First In'][column])}" / "${text(band.values['Last Out'][column])}" not readable`;
                    break;
                }
                if (ot === undefined || late === undefined || early === undefined || worked === undefined) {
                    broken = `day ${day}: a duration is not readable`;
                    break;
                }

                days.push({
                    date,
                    status: text(band.values.Status[column]) || '-',
                    first_in: firstIn,
                    last_out: lastOut,
                    ot_minutes: ot,
                    late_minutes: late,
                    early_minutes: early,
                    worked_minutes: worked,
                });
            }
            if (broken !== null) break;
        }

        if (broken !== null) {
            warnings.push(`${code}: ${broken}; block skipped.`);
            return;
        }
        if (days.length === 0) {
            warnings.push(`${code}: no day columns.`);
            return;
        }

        // The count is the check the two silent shape changes needed.
        const expected = daysInPeriod(from, to);
        if (days.length !== expected) {
            warnings.push(`${code}: ${days.length} of ${expected} days read.`);
        }

        days.sort((a, b) => (a.date < b.date ? -1 : 1));

        employees.push({
            employee_code: code,
            name,
            department: headerField(header, 'Department'),
            designation: headerField(header, 'Designation'),
            days,
        });
    });

    return { period, employees, warnings };
}

/**
 * Every sheet's parse into one. The period must agree across sheets; an
 * employee printed on two sheets is read once and the repeat is named.
 * Warnings keep their sheet's name in front of them when there is more
 * than one sheet to tell apart.
 */
export function mergePunchSheets(sheets: Array<{ name: string; parsed: PunchWorkbook }>): PunchWorkbook {
    const employees: PunchEmployee[] = [];
    const warnings: string[] = [];
    const seen = new Map<string, string>();
    const many = sheets.length > 1;
    let period: PunchWorkbook['period'] = null;

    sheets.forEach(({ name, parsed }) => {
        const say = (message: string) => (many ? `${name}: ${message}` : message);
        parsed.warnings.forEach((warning) => warnings.push(say(warning)));

        const current = period;
        if (parsed.period !== null) {
            if (current === null) {
                period = parsed.period;
            } else if (current.from !== parsed.period.from || current.to !== parsed.period.to) {
                warnings.push(
                    say(`period ${parsed.period.from} to ${parsed.period.to} differs from ${current.from} to ${current.to}; sheet skipped.`),
                );

                return;
            }
        }

        parsed.employees.forEach((employee) => {
            const where = seen.get(employee.employee_code);
            if (where !== undefined) {
                warnings.push(say(`${employee.employee_code} was already read on "${where}"; this copy is skipped.`));

                return;
            }
            seen.set(employee.employee_code, name);
            employees.push(employee);
        });
    });

    return { period, employees, warnings };
}

/** The upload's body, from a parse — what POST /hrms/attendance-imports takes. */
export function punchImportPayload(parsed: PunchWorkbook, fileName: string) {
    if (parsed.period === null) throw new Error('No period in the file.');

    return {
        period_from: parsed.period.from,
        period_to: parsed.period.to,
        source: 'pooja' as const,
        file_name: fileName,
        employees: parsed.employees,
    };
}

/** The one place the file is touched: every sheet → rows → parse → merge. */
export async function readPunchWorkbook(file: Blob): Promise<PunchWorkbook> {
    const XLSX = await import('xlsx');
    const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array' });
    const names = workbook.SheetNames.filter((name) => workbook.Sheets[name] !== undefined);
    if (names.length === 0) return { period: null, employees: [], warnings: ['The workbook has no sheet.'] };

    return mergePunchSheets(
        names.map((name) => ({
            name,
            parsed: parsePunchWorkbook(
                XLSX.utils.sheet_to_json<unknown[]>(workbook.Sheets[name]!, { header: 1, raw: false, defval: null }),
            ),
        })),
    );
}
