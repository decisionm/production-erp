/**
 * Display helpers for the date+time values the API returns.
 *
 * The backend runs in UTC and stores exactly the wall-clock date+time the
 * factory entered (a receipt keyed in as 14:35 is stored as 14:35), so these
 * read the ISO string as written instead of converting it through the
 * browser's timezone — a `new Date(...)` round trip would show that same
 * receipt as 20:05 on an IST machine.
 *
 * Genuine instants stamped by the server (`created_at`) are a different thing
 * and are still fine to show with `toLocaleString()`.
 */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const date = value.slice(0, 10);
    const time = value.slice(11, 16);

    return time !== '' ? `${date} ${time}` : date;
}

/** Just the calendar day, for columns that have always shown a date only. */
export function formatDate(value: string | null | undefined): string {
    return value ? value.slice(0, 10) : '—';
}
