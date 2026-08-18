/**
 * How an item is named in a picker or a table cell.
 *
 * "{sku} — {name}", except when those are the same string, which is the normal
 * case for items pulled from this factory's Tally catalogue: Tally has no
 * separate short code for most products, so the sync stores the full product
 * name in both fields. Concatenating them blindly produced
 *
 *     1 Litre Pet Bottle - Ovel — 1 Litre Pet Bottle - Ovel
 *
 * in every product dropdown on the floor — a supervisor scanning for a bottle
 * mid-setup has to read past the repetition to find the difference between one
 * option and the next.
 *
 * The comparison ignores case AND all whitespace, not merely runs of it: this
 * catalogue spells the same thing "100ml" and "100 Ml", and two fields that
 * agree apart from a space are the same duplication wearing a disguise. Two
 * values that differ only by spacing are, for a reader, one value — so hiding
 * one of them is right rather than lossy.
 *
 * The name is what survives, never the SKU. Where they genuinely differ the SKU
 * is a short code worth showing, so both are kept.
 *
 * A missing item is a legitimate input, not a caller's mistake: several
 * backend resources expose their `item` relation `whenLoaded`, so an endpoint
 * that did not eager-load it omits the key altogether. This is called from
 * ~40 table cells across the app — one absent relation used to throw here and
 * take the whole surrounding table or drawer down with it. A dash is the
 * honest render for "no product on this line".
 */
export function itemLabel(item: { sku?: string | null; name?: string | null } | null | undefined): string {
    if (item === null || item === undefined) return '—';

    const sku = (item.sku ?? '').trim();
    const name = (item.name ?? '').trim();

    if (sku === '' && name === '') return '—';
    if (sku === '') return name;
    if (name === '') return sku;

    const bare = (value: string) => value.toLowerCase().replace(/\s+/g, '');

    return bare(sku) === bare(name) ? name : `${sku} — ${name}`;
}

/**
 * THE UNIT AN ITEM'S QUANTITIES ARE IN, as its own master record spells it.
 *
 * A produced quantity is denominated in the finished item's UOM — the
 * completion posts `quantity_produced` to stock unconverted — so the item
 * master is the only authority on what the number means. Screens that printed
 * a literal unit beside it were stating a unit they had not read: this
 * catalogue says `Nos.` on 77 items, `pcs` on 7 and `kg` on 4, and the same
 * hard-coded word is harmless on the first two and wrong on the third.
 *
 * Null rather than a fallback when the master carries no unit. A product with
 * no UOM cannot start a batch at all (ProductReadinessService fails the `uom`
 * check), so a blank here means an unconfigured product — it is not a licence
 * to assume pieces.
 */
export function uomOf(item: { uom?: string | null } | null | undefined): string | null {
    const raw = (item?.uom ?? '').trim();
    return raw === '' ? null : raw;
}
