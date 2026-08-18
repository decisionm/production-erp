/**
 * The Download / Export Center's wire shapes (GET /exports, POST
 * /exports/{kind}, GET /exports/runs) — the catalogue row and the run row
 * exactly as the Core module's ExportRegistry / ExportRunResource send them.
 */

/** The field types FilterSchema derives from a kind's validation rules. */
export type ExportFilterType = 'date' | 'integer' | 'number' | 'boolean' | 'select' | 'text';

/** An option as the form shows it. */
export interface ExportFilterOption {
    value: string | number;
    label: string;
}

/**
 * One filter field of a kind. `options` is the accepted values of an
 * in:/enum rule — the server sends them as bare values today; an object
 * form {value,label} is accepted too so a richer catalogue later needs no
 * client change.
 */
export interface ExportFilterField {
    name: string;
    /** One of ExportFilterType; anything the client does not know renders as text. */
    type: ExportFilterType | (string & {});
    required: boolean;
    multiple: boolean;
    options: (string | number | ExportFilterOption)[] | null;
}

export type ExportKindStatus = 'available' | 'blocked';

/** One catalogue row: a kind this reader may run (a blocked kind is listed with its reason). */
export interface ExportKind {
    key: string;
    label: string;
    /** The module:X permission group — 'production', 'sales', 'procurement', 'tally-sync', 'core' … */
    module: string;
    status: ExportKindStatus | (string & {});
    blocked_reason: string | null;
    row_cap: number;
    filters: ExportFilterField[];
}

/** One "Recent downloads" row — the caller's own run, streamed or refused. */
export interface ExportRun {
    id: number;
    kind: string;
    filters: Record<string, unknown>;
    row_count: number;
    file_name: string;
    sha256: string | null;
    completed: boolean;
    refusal_reason: string | null;
    created_at: string | null;
}

/** What the form holds before serialisation — dayjs for dates, numbers, strings, booleans, arrays. */
export type ExportFilterValues = Record<string, unknown>;

/** What runExport() hands back: the bytes and the name the server gave them. */
export interface ExportFile {
    filename: string;
    blob: Blob;
}
