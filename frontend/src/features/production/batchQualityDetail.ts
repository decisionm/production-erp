import { readQuantity, type ShiftProductionEntry } from './types';

const DASH = '—';

/** One labelled figure for the quality desk. */
export interface BatchDetailRow {
    label: string;
    value: string;
}

/**
 * Formatted exactly as the quality screen's own fmtKg does, and through the
 * same readQuantity — a figure must not read "12.5000 kg" in the drawer and
 * "12.5 kg" in the column beside it.
 */
export function kg(raw: string | null | undefined): string {
    const n = readQuantity(raw);

    return n === null ? DASH : `${n.toLocaleString('en-IN')} kg`;
}

/**
 * The scrap this batch actually made, which the queue never showed.
 *
 * LUMPS ARE A SEPARATE FIGURE FROM REJECTED PIECES and always have been —
 * ShiftScrapType has both, the reconciliation subtracts both, and Tally posts
 * both on the scrap line. The quality desk was the one place that saw neither,
 * so a batch with heavy lumps looked identical to a clean one until somebody
 * opened the production report.
 *
 * The rejection shown here is PRODUCTION's own figure. Quality's number is the
 * one being typed into the form beside this block, so printing a "confirmed"
 * figure here would either be blank or would echo the checker's own input back
 * at them as though it were evidence.
 *
 * NO "UNACCOUNTED" ROW, and this is not an oversight. The approval desk
 * removed exactly that figure and wrote down why: issued − good − rejection −
 * lumps is ~0 by construction, because nothing weighs a fixed quantity of
 * resin out to a machine, so a batch's issued kg IS good + rejection + lumps
 * and the subtraction returns its own inputs. It reads like a loss figure and
 * is arithmetic. The real "is material missing" question is asked against the
 * common resin input on the Day Bin page, never per batch.
 */
export function scrapSummary(entry: ShiftProductionEntry | null | undefined): BatchDetailRow[] {
    const m = entry?.metrics;

    return [
        { label: 'Lumps', value: kg(m?.lumps_kg) },
        { label: 'Rejection (production)', value: kg(m?.rejection_kg_production ?? entry?.quantity_rejection_kg) },
        { label: 'Material issued', value: kg(m?.issued_kg) },
    ];
}

/**
 * Who and what ran it. Every one of these is already loaded on the queue's own
 * payload (paginate() eager-loads operator and shift); none is a new query.
 *
 * NO MOULD ROW, deliberately. A mould is recorded against a machine changeover
 * (mold_change_logs), never against a batch, so there is no honest per-batch
 * value to print and a joined-up guess would be worse than the absence.
 */
export function runSummary(entry: ShiftProductionEntry | null | undefined): BatchDetailRow[] {
    return [
        { label: 'Operator', value: entry?.operator?.name ?? DASH },
        { label: 'Shift', value: entry?.shift?.name ?? DASH },
        { label: 'Colour', value: entry?.colour ?? DASH },
        {
            label: 'Unit weight',
            value: entry?.unit_weight_grams ? `${entry.unit_weight_grams} g` : DASH,
        },
        { label: 'Downtime', value: downtimeTotal(entry) },
    ];
}

/**
 * Summed from the events themselves, because ProductionMetrics carries no
 * per-batch downtime total — only whether the expectation was netted of it.
 *
 * An ABSENT collection is a dash and an empty one is zero, and the difference
 * matters: "this payload did not load the stoppages" and "this batch ran
 * without stopping" are opposite facts, and a 0 printed for the first is a
 * lie the desk cannot see through.
 */
function downtimeTotal(entry: ShiftProductionEntry | null | undefined): string {
    const events = entry?.downtime_events;
    if (events === undefined || events === null) return DASH;

    const total = events.reduce((sum, event) => sum + (readQuantity(event.minutes) ?? 0), 0);

    return `${total.toLocaleString('en-IN')} min`;
}

/**
 * The stoppages behind that downtime figure, named. A total of ninety minutes
 * means something different when it is one power cut and when it is nine mould
 * jams, and the desk deciding whether to send a batch back is exactly who needs
 * to tell those apart.
 *
 * Absent (not empty) when the payload did not load the events — hence `?? []`,
 * the rule the type comment states for every whenLoaded collection.
 */
export function downtimeLines(entry: ShiftProductionEntry | null | undefined): BatchDetailRow[] {
    return (entry?.downtime_events ?? []).map((event, index) => ({
        // `description` is the reason's readable half; `code` is what the
        // floor picks it by. Either beats "Stoppage 3".
        label: event.reason?.description ?? event.reason?.code ?? `Stoppage ${index + 1}`,
        value: event.minutes === null || event.minutes === undefined ? DASH : `${event.minutes} min`,
    }));
}

/**
 * The scrap rows themselves, one per recorded line, so a reason that was typed
 * at completion is readable at the check rather than only in a report.
 */
export function scrapLines(entry: ShiftProductionEntry | null | undefined): BatchDetailRow[] {
    return (entry?.scraps ?? []).map((scrap) => ({
        label: scrap.scrap_reason?.name ?? (scrap.type === 'lumps' ? 'Lumps' : 'Rejected'),
        value: kg(scrap.quantity_kg),
    }));
}
