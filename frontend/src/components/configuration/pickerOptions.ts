/**
 * THE PICKER HALF of the Configuration Lifecycle Contract.
 *
 * WS-B widened the refusal set on eleven masters — a retired mould, a
 * withdrawn scrap reason, a deactivated GL account and the rest are refused
 * by their FormRequest now. But a widened refusal that the dropdown still
 * offers is not a fix: the operator picks the row the screen showed them,
 * submits, and is handed a raw validation error for a choice the screen
 * invited. The rule has to be applied where the choice is MADE.
 *
 * So: a picker that feeds a write offers ACTIVE rows only.
 *
 * ONE EXCEPTION, and it is the reason this module exists rather than a
 * `.filter()` at fifteen call sites. When the record being edited ALREADY
 * names a row that has since been retired, dropping that row from the list
 * would blank the field and hide what the record actually says — history
 * would be edited by a dropdown. That row therefore stays VISIBLE, marked
 * `(Retired)`, and `disabled`: the reader sees what the record names, and
 * the only thing they can do about it is point at a live row. Which is
 * exactly what the server does — `StoreProductionConfigurationRequest` lets
 * an edit RE-POINT a retired mould but never keep it.
 *
 * Two things this module deliberately does NOT do:
 *
 *  1 · It does not decide what "active" means. Some masters carry
 *      `is_active`, others a status enum where only `retired` is refused
 *      (`molds`, `assets`) — and whether `under_repair` may be scheduled is
 *      an OWNER question nobody has answered. The caller passes the same
 *      predicate its FormRequest uses, and this module never guesses one.
 *
 *  2 · It is not for a FILTER bar, a report or a master list. Those read
 *      history, where a retired row must stay visible AND selectable.
 *      Narrowing them would hide the factory's own past.
 */

/** An antd `Select` option, in the shape every picker in this app already builds. */
export interface PickerOption {
    value: number;
    label: string;
    /** Set only on a retired row kept for history — visible, not choosable. */
    disabled?: boolean;
}

/**
 * How a kept-for-history row is marked. "Retired" is the product's ONE word
 * for the state (see `CONFIGURATION_STATUS_WORDS`) — not "inactive", not
 * "withdrawn", whatever the module's own column happens to be called.
 */
export const RETIRED_OPTION_MARKER = '(Retired)';

/** The row's own words, plus the marker. Nothing is renamed. */
export const retiredOptionLabel = (label: string): string => `${label} ${RETIRED_OPTION_MARKER}`;

export interface ActivePickerSpec<T> {
    /** The FormRequest's rule, in TypeScript. `is_active`, or `status !== 'retired'`. */
    isActive: (row: T) => boolean;
    /** The option this row would have been — the page keeps its own label wording. */
    option: (row: T) => PickerOption;
    /**
     * The value the record being edited already carries, when there is one.
     * A retired row matching it is kept (marked, disabled) instead of vanishing.
     * `null`/`undefined` = a NEW record, so nothing is kept.
     */
    keep?: number | null;
}

/**
 * The rows a picker may offer: every active one, plus — last, marked and
 * disabled — the retired one this record already names.
 */
export function activePickerOptions<T>(
    rows: readonly T[] | undefined | null,
    { isActive, option, keep = null }: ActivePickerSpec<T>,
): PickerOption[] {
    const offered: PickerOption[] = [];
    let kept: PickerOption | null = null;

    for (const row of rows ?? []) {
        const built = option(row);

        if (isActive(row)) {
            offered.push(built);
            continue;
        }

        // Only the ONE row the record names survives being retired, and only
        // once — a list that repeats an id must not print it twice.
        if (keep !== null && keep !== undefined && built.value === keep && kept === null) {
            kept = { ...built, label: retiredOptionLabel(built.label), disabled: true };
        }
    }

    // Appended, never interleaved: the row that cannot be chosen sits after
    // every row that can, so the list never opens on a dead option.
    return kept === null ? offered : [...offered, kept];
}
