/**
 * Which warehouse raw material goes back to when it leaves the day bin.
 *
 * This lived twice — once in the Day Bin page, once in the Shift Floor's bin
 * drawer — and both copies preferred any name containing "store". On this
 * factory's masters that matched **FG Store**, the finished-goods godown, so
 * both screens offered to send PET resin into the warehouse that holds
 * bottles. A default nobody questioned, on a form whose whole job is moving
 * stock. One rule, one place, so the two surfaces cannot disagree about it.
 *
 * Finished-goods and dispatch names are ruled out first, because a wrong
 * confident answer is worse here than no answer: the operator reads a
 * pre-filled field as "the system knows", and resin booked into the bottle
 * store is a stock correction somebody has to find later.
 *
 * Returns undefined when nothing looks like a raw-material store. The caller
 * must then leave the field empty and let a person choose — never fall back to
 * "the first warehouse in the list", which is how this went wrong in the first
 * place.
 */

export interface WarehouseChoice {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
}

/** Names that are definitely NOT where raw material belongs. */
const NOT_RAW = /\bfg\b|finish|dispatch|despatch|sale|delivery|scrap|reject|quarantine/i;

/** Names that say "this is the raw-material / main store". */
const LOOKS_RAW = /\braw\b|\brm\b|resin|granul|material|\bmain\b|\bstore\b|godown/i;

export function guessRawMaterialStoreId(
    warehouses: WarehouseChoice[] | undefined,
    dayBinId: number | null,
): number | undefined {
    const candidates = (warehouses ?? []).filter(
        (w) => w.is_active && w.id !== dayBinId && !NOT_RAW.test(`${w.code} ${w.name}`),
    );

    // An explicit raw-material name wins. Otherwise: exactly one plausible
    // warehouse is an answer (there is nothing to get wrong), but a choice
    // between several is a question for a person, not a coin toss.
    const named = candidates.find((w) => LOOKS_RAW.test(`${w.code} ${w.name}`));

    return (named ?? (candidates.length === 1 ? candidates[0] : undefined))?.id;
}
