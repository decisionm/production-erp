/**
 * The one client-side CSV in the app, and why it is allowed: the rows are
 * ALREADY on the page — at most 200, delivered by a guarded SELECT the
 * server logged against this login. Nothing is read that was not already
 * read. The Export Center stays the door for server-side pulls (lib/csv.ts).
 */
export function resultToCsv(columns: string[], rows: Record<string, unknown>[]): string {
    const cell = (value: unknown): string => {
        if (value === null || value === undefined) return '';
        const text = String(value);
        return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    const lines = [columns.map(cell).join(',')];
    for (const row of rows) lines.push(columns.map((c) => cell(row[c])).join(','));
    return lines.join('\r\n') + '\r\n';
}
