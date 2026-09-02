/**
 * THE POOJA PUNCH REPORT, PARSED IN THE BROWSER (03-Sep design, Track 2).
 *
 * The "Employee day wise master report" is one sheet of employee blocks:
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
 * Blocks are found by their "From:" row, never by a fixed stride, so an
 * extra blank line cannot shift every later employee. A block that cannot
 * be read becomes a warning and the rest of the file still parses.
 *
 * Pure — no SheetJS here — so the vitest fixture (the real July file's
 * rows, names replaced) pins it. `readPunchWorkbook` is the one function
 * that touches the file, and it is the only place `xlsx` is imported.
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

const ROW_LABELS = ['Day', 'Status', 'First In', 'Last Out', 'Total OT', 'Late In', 'Early Out', 'Total Hrs'] as const;
type RowLabel = (typeof ROW_LABELS)[number];

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

function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * The whole sheet as SheetJS's `sheet_to_json(ws, { header: 1 })` gives it
 * — an array of row arrays — into employees and their days.
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

        // The eight labelled rows, found by label within the block.
        const found: Partial<Record<RowLabel, unknown[]>> = {};
        for (let index = start + 1; index < end; index += 1) {
            const rowLabel = text(rows[index]?.[0]) as RowLabel;
            if ((ROW_LABELS as readonly string[]).includes(rowLabel) && found[rowLabel] === undefined) {
                found[rowLabel] = rows[index];
            }
        }
        const missing = ROW_LABELS.filter((rowLabel) => found[rowLabel] === undefined);
        if (missing.length > 0) {
            warnings.push(`${code}: rows missing (${missing.join(', ')}).`);
            return;
        }

        const dayRow = found.Day as unknown[];
        const days: PunchDay[] = [];
        let broken: string | null = null;
        for (let column = 1; column < dayRow.length; column += 1) {
            const day = dayNumber(dayRow[column]);
            if (day === undefined) {
                if (blank(dayRow[column])) continue;
                broken = `day column ${column} reads "${text(dayRow[column])}"`;
                break;
            }
            const date = addDays(from, day - 1);
            if (date > to) {
                broken = `day ${day} falls outside ${from} to ${to}`;
                break;
            }

            const firstIn = parseClock(found['First In']![column]);
            const lastOut = parseClock(found['Last Out']![column]);
            const ot = parseDuration(found['Total OT']![column]);
            const late = parseDuration(found['Late In']![column]);
            const early = parseDuration(found['Early Out']![column]);
            const worked = parseDuration(found['Total Hrs']![column]);
            if (firstIn === undefined || lastOut === undefined) {
                broken = `day ${day}: time "${text(found['First In']![column])}" / "${text(found['Last Out']![column])}" not readable`;
                break;
            }
            if (ot === undefined || late === undefined || early === undefined || worked === undefined) {
                broken = `day ${day}: a duration is not readable`;
                break;
            }

            days.push({
                date,
                status: text(found.Status![column]) || '-',
                first_in: firstIn,
                last_out: lastOut,
                ot_minutes: ot,
                late_minutes: late,
                early_minutes: early,
                worked_minutes: worked,
            });
        }

        if (broken !== null) {
            warnings.push(`${code}: ${broken}; block skipped.`);
            return;
        }
        if (days.length === 0) {
            warnings.push(`${code}: no day columns.`);
            return;
        }

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

/** The one place the file is touched: first sheet → rows → parse. */
export async function readPunchWorkbook(file: Blob): Promise<PunchWorkbook> {
    const XLSX = await import('xlsx');
    const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array' });
    const sheet = workbook.Sheets[workbook.SheetNames[0]];
    if (!sheet) return { period: null, employees: [], warnings: ['The workbook has no sheet.'] };

    const rows = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: false, defval: null });

    return parsePunchWorkbook(rows);
}
