/**
 * The pure logic of the Product Standards workspace, out of the page so it
 * can be tested as plain functions and shared with the Shift Floor.
 *
 * Two families live here:
 *
 *  - The helpers the page always had (fmt, num, attachmentNote,
 *    standardSpec, PACKING_MODE_LABEL, pkg) — moved, not rewritten.
 *  - Phase 5's configuration vocabulary: whether ONE PACKAGING is configured
 *    (counts stated AND a Tally identity of its own — DEC-20260810-003), how
 *    its Tally identity is named, and the mark an item carries while its SKU
 *    is still the name-derived one the Tally pull seeded.
 *
 * Nothing here decides a factory value. The words are made from the server's
 * KEYS; the SKU that should replace a provisional one is the owner's (the SKU
 * format programme is held); a missing identity is reported missing and
 * never guessed from a name.
 */

import type {
    ConfigurationCompleteness,
    PackagingCounts,
    PackagingTallyItem,
    ProductStandardsWorkspaceRow,
    StandardPackagingMode,
    StandardSpecColumn,
    StandardSpecProvenance,
} from '@/features/production/types';

// ---------------------------------------------------------------------------
// The helpers that moved out of the page unchanged
// ---------------------------------------------------------------------------

export const PACKING_MODE_LABEL: Record<StandardPackagingMode, string> = {
    pouch: 'Pouch',
    tray: 'Tray',
    direct_box: 'Straight into the box',
};

/** The standard's packaging row for one mode, if the workbook gave one. */
export const pkg = (r: Pick<ProductStandardsWorkspaceRow, 'packagings'>, mode: StandardPackagingMode) =>
    r.packagings.find((p) => p.mode === mode);

/**
 * EVERY packaging row of one mode, in the order the server sent them. Two
 * same-mode packings can coexist on one standard (Phase 5, D1 — a 490/box
 * tray beside the sheet's 520), and a column that showed only the first
 * would hide the second; the workbook columns render one line per row.
 */
export const packagingsOfMode = <P extends { mode: StandardPackagingMode }>(
    r: { packagings: P[] },
    mode: StandardPackagingMode,
): P[] => r.packagings.filter((p) => p.mode === mode);

/**
 * WHICH packaging row a completion is about — the completion drawer's
 * seed, its per-line lookup and the amend tray recovery all ask this.
 *
 * The id the entry froze at Start (`production_standard_packaging_id`)
 * FIRST: two same-mode packings can coexist on one standard, so the mode
 * alone no longer names one, and a batch started against the 520 tray must
 * not be pre-filled with the 490 tray's counts (quantity_produced is
 * computed from the lines → FG stock and the voucher). The mode is the
 * fallback only — for a batch started before the id was frozen, or a
 * frozen id the preview no longer offers. `onlyMode` narrows the answer to
 * one mode (the amend recovery wants the run's TRAY, not the run's pouch);
 * with it, an id of another mode is not the answer and the mode fallback
 * looks for `onlyMode`. Undefined when nothing matches — the caller keeps
 * whatever default it had; nothing is invented here.
 */
export function packagingForCompletion<P extends { id: number; mode: string }>(
    entry: { production_standard_packaging_id?: number | null; packaging_mode?: string | null } | null | undefined,
    packagings: readonly P[],
    onlyMode?: string | null,
): P | undefined {
    if (!entry || packagings.length === 0) return undefined;

    const frozenId = entry.production_standard_packaging_id ?? null;
    if (frozenId !== null) {
        const byId = packagings.find((p) => p.id === frozenId && (!onlyMode || p.mode === onlyMode));
        if (byId) return byId;
    }

    const mode = onlyMode ?? entry.packaging_mode ?? null;
    if (mode === null || mode === '') return undefined;

    return packagings.find((p) => p.mode === mode);
}

export const fmt = (v: string | number | null | undefined, suffix = ''): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = typeof v === 'number' ? v : parseFloat(v);
    return Number.isNaN(n) ? '—' : `${parseFloat(n.toFixed(4))}${suffix}`;
};

/** A decimal string from the wire, as a number a form control can hold. */
export const num = (v: string | number | null | undefined): number | undefined => {
    if (v === null || v === undefined || v === '') return undefined;
    const n = typeof v === 'number' ? v : parseFloat(v);
    return Number.isNaN(n) ? undefined : n;
};

/**
 * A packing-material spec, plus its provenance entry when one exists.
 *
 * The workbook leaves some of these blank and the factory can still answer
 * them from its own sheet — 375ML KIDNEY's carton is the 500ML KIDNEY's
 * carton, stated two rows above it. What such a fill must never do is *look*
 * like the factory stated it here, which is why the source travels beside the
 * value rather than inside it.
 *
 * A column with no entry in the map came from the workbook verbatim.
 */
export function standardSpec(
    r: Pick<ProductStandardsWorkspaceRow, 'carton_spec' | 'tray_spec' | 'pouch_spec' | 'spec_provenance'>,
    column: StandardSpecColumn,
): { value: string | null; inferred: StandardSpecProvenance | null } {
    const value =
        column === 'carton_spec' ? r.carton_spec : column === 'tray_spec' ? r.tray_spec : r.pouch_spec;

    // `inferred: false` would be a stated value whose origin happens to be
    // recorded — no marker, because it needs no caveat.
    const entry = r.spec_provenance?.[column] ?? null;

    return {
        value: value === null || value === undefined || value === '' ? null : value,
        inferred: entry && entry.inferred !== false ? entry : null,
    };
}

/**
 * How this standard came to point at its Tally item, in one line — or nothing,
 * which is itself the answer for the rows the importer matched by name.
 *
 * `item_attached_by` is a users foreign key, so an endpoint that has not
 * eager-loaded the relation sends a bare id. An id is not a name: "attached by
 * 7" tells nobody anything and reads as a bug. The DATE still says the thing
 * that matters most — that a PERSON decided this in the app, rather than the
 * importer matching two strings — so it is shown either way, named or not.
 */
export const attachmentNote = (
    r: Pick<ProductStandardsWorkspaceRow, 'item_attached_by' | 'item_attached_at'>,
): string | null => {
    const by = r.item_attached_by;
    const name = by !== null && by !== undefined && typeof by !== 'number' ? (by.name ?? '').trim() : '';
    const on = r.item_attached_at ? r.item_attached_at.slice(0, 10) : '';

    if (name === '' && on === '') return null;
    if (name === '') return `attached here · ${on}`;

    return on === '' ? `attached by ${name}` : `attached by ${name} · ${on}`;
};

// ---------------------------------------------------------------------------
// Phase 5 — is this packaging configured, and what is its Tally identity?
// ---------------------------------------------------------------------------

/**
 * The server's keys for a missing piece (ProductVariantService::
 * MISSING_VOCABULARY, in its order), in words. `missing` carries KEYS so
 * that the preview, the review surface and this screen cannot drift into
 * three wordings; the words are made once, here. An unknown key is shown
 * readably (underscores to spaces) rather than dropped — a new server key
 * must never vanish from the screen.
 */
const MISSING_WORDS: Record<string, string> = {
    standard: 'standard',
    cavities: 'cavities',
    unit_weight: 'unit weight',
    cycle_time: 'cycle time',
    packaging: 'packaging',
    counts: 'counts',
    tally_identity: 'Tally identity',
};

const wordFor = (key: string): string => MISSING_WORDS[key] ?? key.replace(/_/g, ' ');

/** "Tally identity", "Tally identity and counts", "a, b and c". Empty for nothing. */
export function missingWords(missing: readonly string[]): string {
    const words = missing.map(wordFor);
    if (words.length === 0) return '';
    if (words.length === 1) return words[0];
    return `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

/** The sentence a screen prints beside an incomplete packing. */
const incompleteWords = (missing: readonly string[]): string => {
    const words = missingWords(missing);
    return words === '' ? 'incomplete' : `incomplete: ${words} missing`;
};

/**
 * An item as far as "can a Tally voucher name it?" goes. Two spellings of
 * the GUID arrive — `guid` from the variants/preview blocks, the column name
 * from the workspace's verbatim Item rows — and both are read.
 */
export interface TallyIdentityLike {
    id?: number;
    sku?: string | null;
    name?: string | null;
    guid?: string | null;
    tally_stock_item_guid?: string | null;
    is_local_fixture?: boolean | null;
}

/**
 * The slice of a packaging this verdict reads. Three producers, one reader:
 * the batch preview and the variants endpoint (`configuration_status`, or
 * the flat `state` + `missing`), and the workspace's plain rows
 * (`is_complete` + `tally_item` only, from which the same answer is derived
 * — with the product's item as the fallback identity, when the caller has it).
 */
export interface PackagingStateInput {
    is_complete?: boolean | null;
    tally_item?: TallyIdentityLike | null;
    configuration_status?: ConfigurationCompleteness | null;
    state?: 'complete' | 'incomplete';
    missing?: string[] | null;
}

export interface PackagingState {
    complete: boolean;
    /** The server's keys, in the server's order — counts before identity. */
    missing: string[];
    /** "incomplete: Tally identity missing"; null when complete. */
    words: string | null;
}

/**
 * Whether one packaging is CONFIGURED: its counts stated AND the identity it
 * WILL post as — its own Tally item, else the product's (DEC-20260810-003)
 * — a real Tally item (carries a GUID, not a local fixture).
 *
 * The SERVER's verdict wins when it sent one, verbatim — the preview and the
 * variants endpoint judge with ProductVariantService, and a screen that
 * second-guessed it would be the two-wordings problem again. Only a row the
 * server did not judge (the workspace's `toArray()` rows) is derived here,
 * by the same rule, from the facts those rows do carry: `is_complete`, the
 * packing's own `tally_item`, and `product` (the standard's item) as the
 * fallback identity. A GUID the payload does not carry at all is treated as
 * unknown, not as missing — this helper never declares a gap it cannot see.
 */
export function packagingState(
    p: PackagingStateInput | null | undefined,
    product?: TallyIdentityLike | null,
): PackagingState {
    const server = serverVerdict(p);
    if (server !== null) {
        return { complete: server.complete, missing: [...server.missing], words: server.complete ? null : incompleteWords(server.missing) };
    }

    const missing: string[] = [];
    if (p?.is_complete === false) missing.push('counts');

    const identity = p?.tally_item ?? product ?? null;
    if (!hasTallyIdentity(identity)) missing.push('tally_identity');

    return {
        complete: missing.length === 0,
        missing,
        words: missing.length === 0 ? null : incompleteWords(missing),
    };
}

/**
 * ProductVariantService::hasTallyIdentity, client-side: a row that exists,
 * carries a Tally GUID and is not a local rehearsal fixture. A GUID key the
 * payload does not carry at all is unknown — counted as present, because a
 * gap this helper cannot see is not one it may declare.
 */
export function hasTallyIdentity(item: TallyIdentityLike | null | undefined): boolean {
    if (item === null || item === undefined) return false;

    if (item.is_local_fixture === true) return false;
    if ((item.sku ?? '').startsWith('LOCAL-')) return false;

    if ('guid' in item && item.guid !== undefined) return item.guid !== null && item.guid !== '';
    if ('tally_stock_item_guid' in item && item.tally_stock_item_guid !== undefined) {
        return item.tally_stock_item_guid !== null && item.tally_stock_item_guid !== '';
    }

    return true;
}

const serverVerdict = (p: PackagingStateInput | null | undefined): { complete: boolean; missing: string[] } | null => {
    if (p === null || p === undefined) return null;

    const status = p.configuration_status;
    if (status && (typeof status.complete === 'boolean' || status.state === 'complete' || status.state === 'incomplete')) {
        const complete = typeof status.complete === 'boolean' ? status.complete : status.state === 'complete';
        return { complete, missing: Array.isArray(status.missing) ? status.missing : [] };
    }

    if (p.state === 'complete' || p.state === 'incomplete') {
        return { complete: p.state === 'complete', missing: Array.isArray(p.missing) ? p.missing : [] };
    }

    return null;
};

/**
 * The Shift Floor's option suffix: the server's own verdict in words, and
 * ONLY the server's — null when the preview carried no `configuration_status`
 * (an older backend), so the caller keeps the wording it always had rather
 * than this module inventing one for a row nobody judged.
 */
export function incompleteWordsFromServer(p: PackagingStateInput | null | undefined): string | null {
    const server = serverVerdict(p);
    if (server === null || server.complete) return null;
    return incompleteWords(server.missing);
}

/**
 * How a packing's Tally identity reads: "sku · name", the name alone when the
 * SKU is the name (this catalogue's normal case — same rule as itemLabel:
 * case and whitespace ignored), and the literal "no Tally identity" for none.
 * The name survives, never the SKU, where one must go.
 */
export function tallyIdentityLabel(
    item: Partial<Pick<PackagingTallyItem, 'id' | 'sku' | 'name'>> | null | undefined,
): string {
    if (item === null || item === undefined) return 'no Tally identity';

    const sku = (item.sku ?? '').trim();
    const name = (item.name ?? '').trim();

    if (sku === '' && name === '') return 'no Tally identity';
    if (sku === '') return name;
    if (name === '') return sku;

    const bare = (value: string) => value.toLowerCase().replace(/\s+/g, '');

    return bare(sku) === bare(name) ? name : `${sku} · ${name}`;
}

/**
 * tallyIdentityLabel with a retirement said out loud — " (archived)" after
 * the label when the row says so. Only the configuration review's
 * separate-product rows carry the flag: they alone resolve a soft-deleted
 * item on purpose (the finding is about the stored column), and "posts as
 * sku · name" with no marker there reads as a live identity over an item no
 * voucher can name any more. Everything without the flag gets the plain
 * label, unchanged.
 */
export function tallyIdentityLabelMarkingArchived(
    item: (Partial<Pick<PackagingTallyItem, 'id' | 'sku' | 'name'>> & { archived?: boolean }) | null | undefined,
): string {
    const label = tallyIdentityLabel(item);

    return item?.archived === true ? `${label} (archived)` : label;
}

// ---------------------------------------------------------------------------
// DEC-20260821-001 — a packing that posts as its own Tally item is a
// separate product
// ---------------------------------------------------------------------------

/**
 * THE SENTENCE, WORDED ONCE. Every surface that has to say "this packing
 * belongs under a separate product" repeats this string rather than phrasing
 * it again, the same way the `missing` vocabulary is worded once above — a
 * supervisor told one thing on the floor and another in the office is the
 * failure this module exists to prevent.
 */
export const SEPARATE_PRODUCT_REQUIRED
    = 'this packing belongs under a separate product';

/**
 * The same fact at explaining length, for an alert rather than a label — the
 * REASON and the INSTRUCTION, and deliberately nothing about what any one
 * screen then does about it.
 *
 * It used to end "The server refuses this start either way", which is true in
 * the Start Batch modal and FALSE in the configuration review: that screen is
 * read-only and reports rows that already exist (and may already have posted
 * vouchers), which nothing refuses retroactively. The consequence clause now
 * lives at the site it is true of (SEPARATE_PRODUCT_START_REFUSED), so a
 * second reader of this string cannot inherit a promise about a screen it is
 * not on.
 */
export const SEPARATE_PRODUCT_REQUIRED_DETAIL
    = 'This packing posts to Tally as its own stock item, which makes it a separate finished product — '
    + 'not a second identity under this one. Pull the Tally masters so that stock item is in the catalogue '
    + '(an item created by hand here carries no Tally GUID and cannot post), create or attach its production '
    + 'standard, then select that product.';

/** Start Batch only: the guard that makes this a dead end rather than a note. */
export const SEPARATE_PRODUCT_START_REFUSED = 'The server refuses this start either way.';

/**
 * Whether a packing's OWN Tally identity names a DIFFERENT item from the
 * product it is offered under — the frontend half of
 * ProductVariantService::identityConflictsWithProduct, and ADVISORY ONLY:
 * the backend refuses the start regardless, and this exists so the refusal
 * is seen before the tap rather than after it.
 *
 * The two compliant answers are false here for the same reasons they are
 * false there: no `tally_item` at all is INHERITANCE (the packing posts as
 * its product, which is most live rows), and an identity equal to the
 * product's item is one product's identity stated twice. Undefined ids —
 * an older backend, or a payload that never carried the product — are
 * false too: nothing is declared a conflict that has not been shown to be
 * one.
 */
export function packagingBelongsToSeparateProduct(
    packaging: { tally_item?: PackagingTallyItem | null } | null | undefined,
    product: { id?: number | null } | null | undefined,
): boolean {
    const packagingItemId = packaging?.tally_item?.id;
    const productItemId = product?.id;

    if (packagingItemId === null || packagingItemId === undefined) return false;
    if (productItemId === null || productItemId === undefined) return false;

    return packagingItemId !== productItemId;
}

/**
 * The tag an item row wears while its SKU is the one the Tally pull seeded
 * from the name and no person has set it (P5-02 `sku_provisional`). The tag
 * says the SKU is provisional and nothing more — what it should become is
 * the SKU format programme's answer, which is the owner's. Tolerant of the
 * flag arriving as a boolean, a 0/1, or "1": three serialisers, one meaning.
 */
export function provisionalSkuTag(
    item: { sku_provisional?: boolean | number | string | null } | null | undefined,
): string | null {
    const flag = item?.sku_provisional;
    return flag === true || flag === 1 || flag === '1' ? 'provisional SKU' : null;
}

/**
 * A packing's counts in one line, for a row that names a packing without
 * showing the whole standard: "24/tray × 20 = 480/box", "120/pouch × 4 =
 * 480/box", "500/box". A count the server did not send prints as "—" — a
 * missing figure is reported missing, never filled in.
 *
 * NEVER A FALSE EQUATION. The importer keeps a row whose three figures
 * disagree exactly as the sheet stated them (105/pouch × 5 pouches beside
 * the sheet's 520/box, flagged "confirm which is correct"); printing
 * "105/pouch × 5 = 520/box" would assert an arithmetic that is not true
 * and hide the very contradiction a person is meant to settle. When the
 * derivation and the stored box count disagree, both are shown apart and
 * the line asks: "sheet says 520/box; 105/pouch × 5 = 525 — confirm which
 * is right". Which is right is the factory's answer, not this function's.
 */
export function packagingCountsSummary(mode: StandardPackagingMode, counts: PackagingCounts | null | undefined): string {
    const c = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : String(v));
    const box = c(counts?.nos_per_box);

    if (mode === 'direct_box') return `${box}/box`;

    const inner = mode === 'pouch' ? counts?.nos_per_pouch : counts?.nos_per_tray;
    const containers = mode === 'pouch' ? counts?.pouches_per_box : counts?.trays_per_box;
    const derivation = `${c(inner)}/${mode} × ${c(containers)}`;

    const derivable = inner !== null && inner !== undefined && containers !== null && containers !== undefined;
    const stored = counts?.nos_per_box;
    if (derivable && stored !== null && stored !== undefined && inner * containers !== stored) {
        return `sheet says ${stored}/box; ${derivation} = ${inner * containers} — confirm which is right`;
    }

    return `${derivation} = ${box}/box`;
}
