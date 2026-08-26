import type { ProductionQueueRow } from '@/features/production/types';

/**
 * THE PRODUCTION QUEUE, GROUPED BY PRODUCT — because the factory runs a
 * PRODUCT, not an order. Three customers waiting for the same bottle is one
 * setup and one mould, and a flat list of three rows hides that.
 *
 * Everything here is a pure read over the server's rows. Nothing is computed
 * that the server did not send, and the server's ORDER is preserved end to
 * end: groups appear in the order their first (highest-priority) request
 * appears, and sub-rows keep the queue order inside a group. The order IS the
 * queue — reorder rewrites it wholesale — so a grouping that re-sorted would
 * be showing something the Start button does not agree with.
 *
 * THREE AGGREGATION TRAPS, all of them correctness rather than polish:
 *
 *   `free` IS ITEM-LEVEL. It is the same finished-goods figure on every row
 *   for the same product, so it is taken ONCE per group and never summed —
 *   summing it would multiply the factory's real stock by the number of
 *   customers waiting for it.
 *
 *   `queued_ahead` IS PER-REQUEST. It counts jobs in front of ONE request, so
 *   it means nothing added up. It stays on the sub-rows.
 *
 *   `cannot_estimate` CASCADES (S12). If any request in a group cannot be
 *   dated, the GROUP cannot be dated — not "the latest of the ones that
 *   could". Dating a group whose head is unknown is exactly the misleading
 *   thing FulfilmentPlanningService was built to refuse.
 */

/** A product, every open request for it, and what can honestly be said about the set. */
export interface ProductionQueueGroup {
    /** Stable across renders — the item id, or the request id when the row carries no item. */
    key: string;
    item: ProductionQueueRow['item'];
    /** The requests, in the server's queue order. Never empty. */
    rows: ProductionQueueRow[];
    /** The best (lowest) priority number in the group — where the product sits in the queue. */
    priority: number;
    /** Total still to make for this product, 4dp decimal string. */
    quantity: string;
    /**
     * Free finished goods for this product — taken ONCE from the group, never
     * summed. Undefined when the caller may not read the planning block (the floor-visibility owner question),
     * which is a different thing from null (no figure).
     */
    free?: string | null;
    /**
     * When the LAST request in this group could be ready, or null when the
     * group cannot be dated. Null whenever `cannot_estimate` is true.
     */
    estimated_ready_date: string | null;
    /** True when ANY request in the group is undatable — the cascade. */
    cannot_estimate: boolean;
    /** The first refusal reason in the group, for the reader who asks why. */
    reason: string | null;
    /** False when the caller may not read the planning block at all (the floor-visibility owner question). */
    planned: boolean;
}

/**
 * Sum 4dp decimal strings without going through float arithmetic.
 *
 * The API states every quantity as a fixed 4dp string and the screen must not
 * turn 0.1 + 0.2 into 0.30000000000000004 in front of a supervisor. Scaling to
 * integer ten-thousandths keeps the sum exact for any piece count this factory
 * will ever queue (well inside JS's safe integer range).
 */
export function sumQuantities(values: Array<string | null | undefined>): string {
    let total = 0;

    for (const value of values) {
        const parsed = Number.parseFloat(value ?? '');
        if (Number.isNaN(parsed)) continue;
        total += Math.round(parsed * 10000);
    }

    return (total / 10000).toFixed(4);
}

/**
 * Group the queue by product, preserving the server's order.
 *
 * A row with no item cannot be grouped with anything — it becomes its own
 * group keyed by request id rather than being folded into a shared "unknown
 * product" bucket, which would claim two unrelated jobs are the same setup.
 */
export function groupQueueByProduct(rows: ProductionQueueRow[]): ProductionQueueGroup[] {
    const order: string[] = [];
    const byKey = new Map<string, ProductionQueueRow[]>();

    for (const row of rows) {
        const key = row.item === null ? `request-${row.id}` : `item-${row.item.id}`;
        const existing = byKey.get(key);

        if (existing === undefined) {
            order.push(key);
            byKey.set(key, [row]);
        } else {
            existing.push(row);
        }
    }

    return order.map((key) => summarise(key, byKey.get(key) as ProductionQueueRow[]));
}

function summarise(key: string, rows: ProductionQueueRow[]): ProductionQueueGroup {
    const head = rows[0];

    // The caller either may read the planning block or may not; the server
    // omits it wholesale, so one row settles it for the group.
    const planned = head.planning !== undefined;

    // THE CASCADE. Any undatable request in the group makes the group
    // undatable — and a caller with no planning block at all is not told the
    // factory cannot estimate, because nobody has said that.
    const cannotEstimate = planned && rows.some((row) => row.planning?.cannot_estimate !== false);

    const dates = rows
        .map((row) => row.planning?.estimated_ready_date)
        .filter((date): date is string => typeof date === 'string');

    return {
        key,
        item: head.item,
        rows,
        // The server orders by priority, so the head carries the group's best.
        priority: head.priority,
        quantity: sumQuantities(rows.map((row) => row.quantity)),
        // ONCE, not summed — the same item-level figure on every row.
        ...(planned ? { free: head.planning?.free ?? null } : {}),
        // The group is done when its LAST job is done. Null the moment
        // anything in it cannot be dated.
        estimated_ready_date: !planned || cannotEstimate || dates.length === 0 ? null : dates.reduce((a, b) => (a > b ? a : b)),
        cannot_estimate: cannotEstimate,
        reason: rows.map((row) => row.planning?.reason ?? null).find((reason) => reason !== null) ?? null,
        planned,
    };
}
