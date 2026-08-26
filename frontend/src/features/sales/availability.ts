import type { ItemAvailability } from './types';

/**
 * THE PER-LINE AVAILABILITY CHIPS on the New Sales Order modal — free, held,
 * and (only once a quantity is typed) short.
 *
 * WHAT THE CHIPS ARE NOT. They are not a promise: seeing free stock does not
 * hold it, a hold is the store's act on the fulfilment queue, and two desks
 * reading the same figure a second apart can both be told 500 are free. The
 * server recomputes under a lock when somebody actually reserves and refuses
 * the second one with the real number.
 *
 * UNKNOWN IS NEVER ZERO. Until the availability read answers for an item,
 * there are no chips at all — a "0 free" printed while a request is in flight
 * would talk a desk out of an order the factory can fill.
 */

export type AvailabilityChipTone = 'success' | 'neutral' | 'warning';

export interface AvailabilityChip {
    key: 'free' | 'held' | 'short' | 'over_reserved';
    label: string;
    tone: AvailabilityChipTone;
}

/** A 4dp decimal string trimmed for display: "1250.5000" → "1250.5". */
function trim(value: string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(Number(parsed.toFixed(4))) : value;
}

function figure(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * The chips for one order line.
 *
 * `availability` absent (not yet read, or the read failed) ⇒ NO chips. The
 * modal shows nothing rather than a figure it does not have.
 *
 * `short` is the only chip that is a projection about what the user is
 * TYPING — quantity beyond what is free — so it appears only once a quantity
 * has been entered, and it is a warning rather than a refusal: an order for
 * more than the shelf holds is an ordinary order, and the shortfall is what
 * the store will send to production.
 *
 * `over_reserved` is printed when the server reports it (S8). Free is clamped
 * at zero, so without this chip a desk sees a full shelf promising nothing and
 * has no way to find out why.
 */
export function availabilityChips(
    availability: ItemAvailability | undefined,
    quantity: number | null | undefined,
): AvailabilityChip[] {
    if (availability === undefined) return [];

    const free = figure(availability.free);
    const reserved = figure(availability.reserved);
    const overReserved = figure(availability.over_reserved);

    const chips: AvailabilityChip[] = [];

    if (free !== null) {
        chips.push({ key: 'free', label: `${trim(availability.free)} free`, tone: free > 0 ? 'success' : 'neutral' });
    }

    if (reserved !== null && reserved > 0) {
        chips.push({ key: 'held', label: `${trim(availability.reserved)} held`, tone: 'neutral' });
    }

    if (overReserved !== null && overReserved > 0) {
        chips.push({
            key: 'over_reserved',
            label: `${trim(availability.over_reserved)} promised twice`,
            tone: 'warning',
        });
    }

    if (free !== null && typeof quantity === 'number' && Number.isFinite(quantity) && quantity > free) {
        chips.push({
            key: 'short',
            label: `short ${trim(String(quantity - free))}`,
            tone: 'warning',
        });
    }

    return chips;
}

/**
 * The distinct item ids on the form, sorted, as the ONE availability request
 * this modal makes.
 *
 * Sorted so the query key is stable: re-ordering two lines is not a new
 * question, and TanStack would otherwise refetch the same four items because
 * they arrived in a different order.
 */
export function availabilityItemIds(lines: { item_id?: number | null }[]): number[] {
    const ids = new Set<number>();

    for (const line of lines) {
        if (typeof line.item_id === 'number' && Number.isFinite(line.item_id)) ids.add(line.item_id);
    }

    return [...ids].sort((a, b) => a - b);
}

/** The rows keyed by item, so a line finds its own figures without a scan per render. */
export function availabilityByItem(rows: ItemAvailability[] | undefined): Map<number, ItemAvailability> {
    return new Map((rows ?? []).map((row) => [row.item_id, row]));
}
