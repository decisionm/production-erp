/**
 * Tiny client-side CSV builder for the "Export CSV" buttons on report tabs.
 * Exports exactly the rows the table is showing — no backend involvement.
 */

function escapeCell(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '';
    let s = String(value);
    // Formula-injection guard: neutralise cells a spreadsheet would execute
    // (=, +, -, @, tab, CR starts) with a leading apostrophe — but leave
    // plain numbers (incl. negatives like -0.4000) untouched.
    if (/^[=+\-@\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) {
        s = `'${s}`;
    }
    // RFC 4180: quote cells containing commas, quotes or newlines; double quotes.
    return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function toCsv(headers: string[], rows: (string | number | null | undefined)[][]): string {
    return [headers, ...rows].map((row) => row.map(escapeCell).join(',')).join('\r\n');
}

/** Trigger a browser download of the CSV. BOM prefix so Excel opens it as UTF-8. */
export function downloadCsv(filename: string, csv: string): void {
    const blob = new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
