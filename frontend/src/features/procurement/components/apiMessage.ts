/**
 * The server's own sentence when it sent one (a 422's `message` — "amend
 * only in Draft — short-close or cancel", "Tally-originated order: change
 * it in Tally"), the fallback otherwise. Never genericised: the refusal
 * names the rule, and the rule is what the reader needs.
 */
export function apiMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { status?: number; data?: { message?: string } } } | undefined)?.response;
    const message = response?.data?.message;

    return message && message.trim() !== '' ? message : fallback;
}

/** The HTTP status behind an axios error, or null. */
export function apiStatus(error: unknown): number | null {
    return (error as { response?: { status?: number } } | undefined)?.response?.status ?? null;
}
