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
