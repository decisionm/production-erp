import type { ExportFilterField, ExportFilterOption, ExportFilterValues, ExportKind, ExportRun } from './types';

/**
 * Pure helpers behind the Download / Export Center. No React, no axios —
 * everything here is a function of its arguments so it can be tested
 * without a DOM (filters.test.ts):
 *
 *  - the catalogue's filter schema → which form control draws each field;
 *  - the form's values → the JSON body POST /exports/{kind} validates
 *    (the SAME rules the module's list request uses — the client never
 *    re-validates, it only serialises honestly);
 *  - the server's Content-Disposition → the file name to save under;
 *  - the server's refusal body → the sentence to show, verbatim.
 */

/** The controls the Center's generated form knows how to draw. */
export type FilterControl = 'date' | 'integer' | 'number' | 'boolean' | 'select' | 'text';

/**
 * Schema field → form control. A `select` needs values to select from: a
 * select field the server sent without options (an enum the client cannot
 * enumerate) degrades to text rather than an empty dropdown. Anything the
 * client does not recognise is text — the server still validates it.
 * Id fields (customer_id, shift_id, …) arrive as `integer` and render as
 * a number box: no extra lookups in this phase, by design.
 */
export function controlFor(field: Pick<ExportFilterField, 'type' | 'options'>): FilterControl {
    switch (field.type) {
        case 'date':
            return 'date';
        case 'integer':
            return 'integer';
        case 'number':
            return 'number';
        case 'boolean':
            return 'boolean';
        case 'select':
            return optionsOf(field).length > 0 ? 'select' : 'text';
        default:
            return 'text';
    }
}

/**
 * The options as the form shows them. The server sends the accepted values
 * bare (['pending', 'synced']); an object form {value, label} is accepted
 * too. Anything else (null, an empty list) is no options.
 */
export function optionsOf(field: Pick<ExportFilterField, 'options'>): ExportFilterOption[] {
    if (!Array.isArray(field.options)) return [];

    const options: ExportFilterOption[] = [];
    for (const raw of field.options) {
        if (typeof raw === 'string' || typeof raw === 'number') {
            options.push({ value: raw, label: String(raw) });
        } else if (raw && typeof raw === 'object' && 'value' in raw) {
            const value = raw.value;
            if (typeof value !== 'string' && typeof value !== 'number') continue;
            const label = typeof raw.label === 'string' && raw.label !== '' ? raw.label : String(value);
            options.push({ value, label });
        }
    }

    return options;
}

/**
 * A rule key as a form label: `date_from` → "Date from", `shift_id` →
 * "Shift ID", `q` → "Search". Presentation only — the wire name is the
 * rule key and never changes.
 */
export function fieldLabel(name: string): string {
    if (name === 'q') return 'Search';

    const words = name
        .split(/[_\s]+/)
        .filter((word) => word !== '')
        .map((word) => (word.toLowerCase() === 'id' ? 'ID' : word));
    if (words.length === 0) return name;

    const [first, ...rest] = words;

    return [first.charAt(0).toUpperCase() + first.slice(1), ...rest].join(' ');
}

/**
 * A business date on the wire is YYYY-MM-DD, full stop. A DatePicker hands
 * the form a dayjs (anything with format()); a caller may hand it a string
 * — an ISO instant is cut to its date so the server's date rule never sees
 * a time part it would refuse.
 */
function businessDay(value: unknown): string | undefined {
    if (value && typeof value === 'object' && 'format' in value && typeof value.format === 'function') {
        return String(value.format('YYYY-MM-DD'));
    }
    if (typeof value === 'string') {
        const text = value.trim();
        if (text === '') return undefined;

        return /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(0, 10) : text;
    }

    return undefined;
}

function scalar(field: Pick<ExportFilterField, 'type'>, value: unknown): string | number | boolean | undefined {
    if (value === undefined || value === null) return undefined;

    if (field.type === 'date') return businessDay(value);

    if (typeof value === 'boolean') {
        // A Switch is two-state: on = "only these"; off = no filter, not
        // "only the others" — the same convention every filter bar in the
        // app follows for its boolean flags (Tally Sync `held`, quality
        // `due`), so the file matches the screen for the same bar.
        return value ? true : undefined;
    }

    if (typeof value === 'number') return Number.isFinite(value) ? value : undefined;

    if (typeof value === 'string') {
        const text = value.trim();

        return text === '' ? undefined : text;
    }

    return undefined;
}

/**
 * Form values → the JSON body of POST /exports/{kind}, with everything
 * empty left out. ONLY the schema's fields are read (an allowlist, so a
 * stray form key never reaches the wire); a `multiple` field goes out as
 * an array minus blank members, and an empty array is no filter at all —
 * an empty Select must not send `status: []` and turn "no filter" into
 * "match nothing".
 */
export function serialiseFilters(
    fields: readonly ExportFilterField[],
    values: ExportFilterValues | null | undefined,
): Record<string, string | number | boolean | (string | number | boolean)[]> {
    const body: Record<string, string | number | boolean | (string | number | boolean)[]> = {};
    if (!values || typeof values !== 'object') return body;

    for (const field of fields) {
        const raw = values[field.name];
        if (raw === undefined || raw === null) continue;

        if (field.multiple) {
            const members = (Array.isArray(raw) ? raw : [raw])
                .map((member) => scalar(field, member))
                .filter((member): member is string | number | boolean => member !== undefined);
            if (members.length > 0) body[field.name] = members;
            continue;
        }

        const value = scalar(field, raw);
        if (value !== undefined) body[field.name] = value;
    }

    return body;
}

/**
 * The name to save the file under, read from the server's
 * Content-Disposition — `attachment; filename=sales_orders-20260817-1030.csv`,
 * the quoted form, or the RFC 5987 `filename*=UTF-8''…` form (preferred
 * when both are present, as the RFC says). Any path is cut to its last
 * segment; when the header is missing or unreadable the fallback is used,
 * so a download never lands as "blob".
 */
export function filenameFromDisposition(header: string | null | undefined, fallback: string): string {
    if (!header) return fallback;

    const extended = /filename\*\s*=\s*(?:[\w-]*)'[\w-]*'([^;]+)/i.exec(header);
    if (extended) {
        try {
            const decoded = decodeURIComponent(extended[1].trim().replace(/^"|"$/g, ''));
            const name = lastSegment(decoded);
            if (name) return name;
        } catch {
            // Malformed percent-encoding: fall through to the plain form.
        }
    }

    const plain = /filename\s*=\s*("([^"]*)"|([^;]+))/i.exec(header);
    if (plain) {
        const name = lastSegment((plain[2] ?? plain[3] ?? '').trim());
        if (name) return name;
    }

    return fallback;
}

function lastSegment(name: string): string {
    return name.split(/[\\/]/).pop()?.trim() ?? '';
}

/**
 * The server's refusal, as one sentence to show word for word: the
 * `message` of a 409 (blocked), a 422 (over the cap — "N rows match; the
 * cap is …"), a 403; for a validation 422 the field sentences themselves,
 * which say more than the generic message above them. Nothing here is
 * composed by the client beyond joining what the server sent; the fallback
 * is only for a body that carries no sentence at all.
 */
export function refusalSentence(body: unknown, fallback: string): string {
    if (!body || typeof body !== 'object') return fallback;

    const record = body as { message?: unknown; errors?: unknown };

    if (record.errors && typeof record.errors === 'object') {
        const sentences = Object.values(record.errors as Record<string, unknown>)
            .flatMap((value) => (Array.isArray(value) ? value : [value]))
            .filter((value): value is string => typeof value === 'string' && value.trim() !== '');
        if (sentences.length > 0) return sentences.join(' ');
    }

    if (typeof record.message === 'string' && record.message.trim() !== '') return record.message;

    return fallback;
}

/**
 * The catalogue grouped by module, in catalogue order (the server lists a
 * module's kinds together, in registration order; the first kind of a
 * module fixes where the module's group sits).
 */
export function groupByModule(kinds: readonly ExportKind[]): { module: string; kinds: ExportKind[] }[] {
    const groups: { module: string; kinds: ExportKind[] }[] = [];
    for (const kind of kinds) {
        const group = groups.find((candidate) => candidate.module === kind.module);
        if (group) group.kinds.push(kind);
        else groups.push({ module: kind.module, kinds: [kind] });
    }

    return groups;
}

/** The module:X permission group as a heading; an unknown key humanised, never hidden. */
export function moduleLabel(module: string): string {
    switch (module) {
        case 'production':
            return 'Production';
        case 'sales':
            return 'Sales';
        case 'procurement':
            return 'Procurement';
        case 'tally-sync':
            return 'Tally Sync';
        case 'inventory':
            return 'Inventory';
        case 'finance':
            return 'Finance';
        case 'core':
            return 'Administration';
        default:
            return fieldLabel(module.replace(/-/g, '_'));
    }
}

/**
 * A run's filters as one line for the "Recent downloads" table:
 * `date=2026-08-17 · shift_id=3`; arrays comma-joined; "—" when the run
 * carried no filters.
 */
export function filtersSummary(filters: Record<string, unknown> | null | undefined): string {
    if (!filters || typeof filters !== 'object') return '—';

    const parts = Object.entries(filters)
        .filter(([, value]) => value !== undefined && value !== null && value !== '')
        .map(([key, value]) => `${key}=${Array.isArray(value) ? value.join(',') : String(value)}`);

    return parts.length > 0 ? parts.join(' · ') : '—';
}

/**
 * What became of a run: streamed to the end, refused (with the server's
 * reason, verbatim), or started and never finished — a run row is written
 * BEFORE the stream and stamped completed after the last byte, so a
 * connection that dropped mid-file leaves the third state, and it is
 * said rather than shown as a success.
 */
export function runOutcome(run: Pick<ExportRun, 'completed' | 'refusal_reason'>): {
    state: 'completed' | 'refused' | 'incomplete';
    text: string;
} {
    if (run.completed) return { state: 'completed', text: 'Completed' };
    if (run.refusal_reason) return { state: 'refused', text: run.refusal_reason };

    return { state: 'incomplete', text: 'Not completed' };
}
