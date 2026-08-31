/**
 * DECIMAL QUANTITIES, ADDED WITHOUT FLOATS.
 *
 * Every quantity on the wire is a decimal string at four places ('500.0000').
 * Read as a JS number and added back, three lots of '0.1000' become
 * 0.30000000000000004, and a fully-received line reads as short — so
 * procurement has always done this arithmetic on integers scaled by 10^4
 * instead. These functions were private to purchaseOrders.ts, where
 * reconcileReceipts needed them; they live here now because the requisition
 * screens need exactly the same guarantee and a second, floating-point copy
 * of it is how the drift gets back in.
 *
 * Nothing here parses what it cannot: an unreadable value is `null`, never
 * NaN and never a guess. The caller decides what an unreadable quantity
 * means — reconcileReceipts marks the row `unknown`, quantitiesByUom leaves
 * it out of both totals.
 */

export const SCALE = 4;

/** '500.0000' → 5000000n. Null when the value is not a plain decimal. */
export function toScaled(value: string | number | null | undefined): bigint | null {
    if (value === null || value === undefined) return null;
    const text = String(value).trim();
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
    if (!match) return null;
    const [, sign, whole, fraction = ''] = match;
    const scaledFraction = (fraction + '0'.repeat(SCALE)).slice(0, SCALE);
    const scaled = BigInt(whole) * 10n ** BigInt(SCALE) + BigInt(scaledFraction);

    return sign === '-' ? -scaled : scaled;
}

/** 5000000n → '500.0000' — always four places, the spelling the wire uses. */
export function fromScaled(value: bigint): string {
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const whole = abs / 10n ** BigInt(SCALE);
    const fraction = (abs % 10n ** BigInt(SCALE)).toString().padStart(SCALE, '0');

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/**
 * '500.0000' → '500'; '2.5000' → '2.5'. For a ONE-LINE label, where four
 * zeros per figure on three figures push the useful part off the end. A
 * quantity COLUMN keeps its places — it is read for precision. Never touches
 * a value it cannot parse.
 */
export function trimQuantity(value: string): string {
    return /^-?\d+\.\d+$/.test(value) ? value.replace(/\.?0+$/, '') : value;
}
