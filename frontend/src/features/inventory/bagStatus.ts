/**
 * WHAT STATE A PHYSICAL BAG IS IN — the label bench's pure half.
 *
 * SIX states, not four. `App\Modules\Inventory\Models\Enums\MaterialBagStatus`
 * carries in_store, in_day_bin, consumed, returned, waiting_qc and
 * rejected_qc, and the exported `MaterialBagStatus` union in
 * features/production/types.ts now names all six too (it named four until
 * 27-Aug-2026, which is why this table exists).
 *
 * It still reads the status as the STRING the server sent rather than as that
 * union, and deliberately: a value this build has not met reads as ITSELF
 * rather than as a blank tag, and the enum is one that gets added to. A bag
 * whose state is not shown is a bag somebody will treat as available.
 *
 * The order below is the order the filter offers, and it is the bag's life:
 * waiting on QC, released to the store, standing at a machine, consumed —
 * with the two dead ends last.
 */
export const BAG_STATUSES = [
    'waiting_qc',
    'in_store',
    'in_day_bin',
    'consumed',
    'returned',
    'rejected_qc',
] as const;

const TEXT: Record<string, string> = {
    waiting_qc: 'Waiting QC',
    in_store: 'In store',
    in_day_bin: 'In day bin',
    consumed: 'Consumed',
    returned: 'Returned',
    rejected_qc: 'Rejected QC',
};

const TONE: Record<string, string> = {
    waiting_qc: 'gold',
    in_store: 'success',
    in_day_bin: 'processing',
    consumed: 'default',
    returned: 'warning',
    rejected_qc: 'error',
};

export interface BagStatusLabel {
    text: string;
    tone: string;
}

export function bagStatusLabel(status: string | null | undefined): BagStatusLabel {
    if (status === null || status === undefined || status === '') {
        return { text: '—', tone: 'default' };
    }

    return {
        text: TEXT[status] ?? status.replaceAll('_', ' '),
        tone: TONE[status] ?? 'default',
    };
}

/** The filter's options, in the order above. */
export function bagStatusOptions(): { value: string; label: string }[] {
    return BAG_STATUSES.map((status) => ({ value: status, label: bagStatusLabel(status).text }));
}

/**
 * A bag's weight as the register prints it. The server sends decimal STRINGS
 * and they are parsed only to trim trailing zeros for display — never to
 * compute with, and never rounded into a figure the factory would then read
 * back as a measurement.
 */
export function formatKg(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(parseFloat(parsed.toFixed(4))) : '—';
}
