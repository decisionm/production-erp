/**
 * ONE reading of an API failure, for the whole app.
 *
 * SIX handlers had hand-rolled this and every one lost information doing it:
 * `ProductStandardsPage`, `ProductionConfigurationPage`, `ApproveProductionPage`,
 * `ShiftProductionEntryPage`, `PackingMaterialsTab` and
 * `ConfigurationReviewPanel` each did `Object.values(errors).flat().join(' ')`,
 * which throws the FIELD KEYS away. A refusal then reads "This field is
 * required. Must be a number." with nothing saying WHICH field — on a
 * standards form with thirty inputs that is not an answer, it is a hunt. The
 * keys are the most useful half of a Laravel validation body and this helper
 * keeps them. All six read through this module now.
 *
 * Everything here is pure: it reads an axios rejection and returns data. The
 * rendering (an antd modal) lives in `showApiError.tsx` so that this file can
 * be unit-tested in vitest's node environment, with no DOM and no antd.
 *
 * Nothing here decides a factory value or softens a refusal — the server's
 * words are printed verbatim, and the only thing added is the key that names
 * the field they belong to.
 */

/**
 * The words five of the six hand-rolled handlers ended on. Kept, so adoption
 * changed nothing but the keys. The two cancel paths ended on their own
 * sentence and pass it as the `fallback` argument instead.
 */
export const FALLBACK_API_ERROR = 'Unexpected error.';

export interface ApiFieldError {
    /** The server's own key, verbatim — `packagings.0.nos_per_box`. */
    field: string;
    /** The same key, made readable, without renaming anything. */
    label: string;
    /** Every message the server sent for that key, in its order. */
    messages: string[];
}

export interface ApiErrorParts {
    /** The headline the server gave, or the shared fallback. */
    message: string;
    /** A DomainException's stable code (`configuration_in_use`, …) when there is one. */
    code: string | null;
    /** Validation errors, keys kept, in the order the server listed them. */
    fields: ApiFieldError[];
}

/** The response body of any axios rejection, without pretending to know its shape. */
const body = (error: unknown): Record<string, unknown> => {
    const data = (error as { response?: { data?: unknown } } | undefined)?.response?.data;
    return data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : {};
};

/**
 * A validation key made readable WITHOUT being renamed: underscores become
 * spaces and an array index becomes the row number a person would count
 * ("packagings.0.nos_per_box" → "Packagings #1 → nos per box"). The raw key
 * stays on the field object, so nothing is lost if the pretty form is odd.
 */
export function fieldLabel(key: string): string {
    if (key === '') return '';
    const parts: string[] = [];
    for (const segment of key.split('.')) {
        if (/^\d+$/.test(segment)) {
            // Rows are counted from one on screen; the server counts from zero.
            const numbered = `#${Number(segment) + 1}`;
            if (parts.length === 0) parts.push(numbered);
            else parts[parts.length - 1] = `${parts[parts.length - 1]} ${numbered}`;
            continue;
        }
        parts.push(segment.replace(/_/g, ' '));
    }
    const joined = parts.join(' → ');
    return joined.charAt(0).toUpperCase() + joined.slice(1);
}

/**
 * Everything worth showing about one failed request, read once.
 *
 * `fallback` is the caller's own last words for a failure the server did not
 * describe ("Refresh and try again."). It is only ever reached when there is
 * no server message: the server's sentence always wins, because it is the one
 * that knows what actually happened.
 */
export function apiErrorParts(error: unknown, fallback: string = FALLBACK_API_ERROR): ApiErrorParts {
    const data = body(error);
    const rawErrors = data.errors;
    const fields: ApiFieldError[] = [];

    if (rawErrors !== null && typeof rawErrors === 'object') {
        for (const [field, value] of Object.entries(rawErrors as Record<string, unknown>)) {
            const messages = (Array.isArray(value) ? value : [value]).filter(
                (m): m is string => typeof m === 'string' && m !== '',
            );
            if (messages.length > 0) fields.push({ field, label: fieldLabel(field), messages });
        }
    }

    const message = typeof data.message === 'string' && data.message !== '' ? data.message : fallback;

    return {
        message,
        code: typeof data.code === 'string' && data.code !== '' ? data.code : null,
        fields,
    };
}

/**
 * The one-line form, for a toast or a form-level alert. Field errors win over
 * the generic "The given data was invalid." headline, because the headline
 * says nothing the reader can act on.
 */
export function apiErrorSummary(error: unknown, fallback: string = FALLBACK_API_ERROR): string {
    const parts = apiErrorParts(error, fallback);
    if (parts.fields.length === 0) return parts.message;
    return parts.fields.map((f) => `${f.label}: ${f.messages.join(' ')}`).join(' · ');
}
