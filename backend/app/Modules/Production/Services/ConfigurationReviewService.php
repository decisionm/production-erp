<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Database\Eloquent\Collection;

/**
 * The configuration review: what a person still has to settle before every
 * packing of every product posts as ONE known Tally item (Phase 5, P5-03).
 *
 * FOUR KINDS OF ROW, in this order:
 *
 *   packaging_separate_product — a packaging that carries a Tally identity
 *       of its OWN which names a DIFFERENT item from the product its
 *       standard is attached to. DEC-20260821-001 (superseding
 *       DEC-20260810-003) says that pair is two finished products, not two
 *       packing identities under one — so this row is EVIDENCE about
 *       configuration that already exists, listed FIRST because until the
 *       separate product exists the packing's other gaps are questions
 *       about the wrong row. ADVISORY and non-mutating: `fix_target` is
 *       `separate_product`, no candidates are offered and no link would
 *       help — the answer is a Tally stock item pulled into the catalogue
 *       and a production standard of its own, neither of which this screen
 *       can make. The identity is NEVER cleared here: this list is ADVISORY
 *       and rewrites neither the current configuration nor posted history.
 *       The column is the CURRENT configuration's own evidence — what this
 *       packing is set up to post as — and it is not the record of what
 *       already posted: see FIX_SEPARATE_PRODUCT below for the two places
 *       that hold that.
 *   packaging_no_identity — a packaging whose resolved identity (its own
 *       item, else the product's — DEC-20260810-003) is null, carries no
 *       Tally GUID, or is a LOCAL- fixture; and, when nothing on a standard
 *       inherits the product's identity (no packagings, or every packaging
 *       has its own), the standard itself, once (packaging: null).
 *   packaging_ambiguous   — a packaging whose resolved identity's NAME is
 *       carried by more than one ERP item (LineMappingResolver's
 *       `ambiguous`, read through ProductVariantService): Tally would match
 *       one by name and this ERP cannot say which. ADVISORY: linking any of
 *       the rows that share the name does not clear the ambiguity — Tally
 *       still matches the voucher line by that name — so the fix_target is
 *       `name_ambiguity` (no Link offered; the rows sharing the name are
 *       listed as information). The duplicate is a catalogue question and
 *       block-vs-warn is the owner's (Q43).
 *   item_provisional_sku  — an item still carrying the SKU the masters pull
 *       seeded from its Tally name (items.sku_provisional).
 *
 * EVERY LINKABLE ROW OFFERS CANDIDATES — the existing Tally items a person could
 * LINK: exact/normalised-name matches (case, trim, runs of whitespace) among
 * ACTIVE, Tally-pulled rows that are not fixtures (the identity requests
 * refuse an inactive item, so offering one would offer a refusal).
 * Deliberately nothing cleverer:
 * the standards page already has a scored picker
 * (ProductionStandardController::itemCandidates) for the hard cases; this
 * list is for the obvious ones, and a near-miss offered here would put the
 * wrong bottle on a packing. The link itself is made through the endpoints
 * that already exist — PATCH standards/{standard}/packagings/{packaging}/
 * identity (item_id only, never a count), POST standards/{standard}/
 * attach-item, PUT inventory/items/{item} (sku) — and `fix_target` names
 * which. THIS SERVICE WRITES NOTHING, and the
 * ERP never creates a Tally-less item for real production: the answer to a
 * gap is always an item Tally already has.
 *
 * Read-only and derived on every call — no stored verdicts, no reviewed
 * flags. A gap closed by a link disappears from the list on the next read.
 */
class ConfigurationReviewService
{
    /**
     * A packing whose own Tally identity is a DIFFERENT item from its
     * product's — the shape DEC-20260821-001 withdrew the authority for.
     */
    public const KIND_PACKAGING_SEPARATE_PRODUCT = 'packaging_separate_product';

    public const KIND_PACKAGING_NO_IDENTITY = 'packaging_no_identity';

    public const KIND_PACKAGING_AMBIGUOUS = 'packaging_ambiguous';

    public const KIND_ITEM_PROVISIONAL_SKU = 'item_provisional_sku';

    /** Where the fix goes: the packaging's own item_id (PATCH packagings/{packaging}/identity). */
    public const FIX_PACKAGING_ITEM = 'packaging_item';

    /** The product's item (POST standards/{standard}/attach-item). */
    public const FIX_ATTACH_ITEM = 'attach_item';

    /** The item's SKU (PUT inventory/items/{item}). */
    public const FIX_ITEM_SKU = 'item_sku';

    /**
     * Nothing this ERP can link fixes it: the NAME is shared by more than
     * one catalogue row, and Tally matches by name. Advisory — a person
     * settles the duplicate in the catalogue (Q43).
     */
    public const FIX_NAME_AMBIGUITY = 'name_ambiguity';

    /**
     * NOTHING ON THIS SCREEN FIXES IT, and that is the point of giving it a
     * target of its own rather than reusing `packaging_item`. The packing
     * posts as its own Tally stock item, so under DEC-20260821-001 it is a
     * separate finished product: the answer is that stock item in the ERP
     * catalogue (which only the masters pull can put there — an item made by
     * hand carries no Tally GUID and cannot post) plus a production standard
     * of its own, and then the packing configured under THAT product.
     *
     * Deliberately NOT `packaging_item`: that target renders a Link control,
     * and every link this screen could offer writes the very column the
     * decision withdrew the authority for. Nor is it a "clear the identity"
     * action — and NOT because the column is the only record of what already
     * posted. IT IS NOT THAT RECORD, and saying so was wrong:
     *
     *  - A completed run FROZE its own identity in
     *    `shift_production_entries.finished_item_id` at completion
     *    (ShiftProductionEntryService::completeBatch), and every surface that
     *    posts, prints or traces the produced item reads that frozen column
     *    through `effectiveItem()` — including the voucher payload build and
     *    the retry rebuild (TallySyncService::regeneratePayload), neither of
     *    which consults this configuration column at all.
     *  - The queued voucher and its append-only event history
     *    (`tally_sync_entries`, `tally_sync_events`) are the accountant's own
     *    trail, and a voucher Tally has accepted is refused a rebuild
     *    outright (`TallySyncEntry::isInTally()`).
     *
     * The reason to leave the column alone is the plain one: it is the
     * CURRENT configuration's evidence — the answer somebody recorded for
     * this packing — and a read-only review has no business erasing it. The
     * packaging service already treats it as evidence that blocks a hard
     * delete (DEC-20260817-002 §4).
     */
    public const FIX_SEPARATE_PRODUCT = 'separate_product';

    /** The suffix the product-master import puts on a fixture's name. */
    private const LOCAL_FIXTURE_NAME_SUFFIX = '(LOCAL FIXTURE)';

    /** @var ?array<string, list<Item>> normalised name => the linkable Tally items carrying it, once per call */
    private ?array $linkable = null;

    public function __construct(private readonly ProductVariantService $variants) {}

    /**
     * @return array{rows: list<array<string, mixed>>}
     */
    public function review(): array
    {
        $this->linkable = null;

        $standards = ProductionStandard::query()
            // BOTH ENDS of the relation, each in BOTH resolutions, and all
            // four are load-bearing.
            // `item` and `packagings.tallyItem` are the HIDING pair, what
            // identityFor() reads for the three older lists, and they must
            // stay hiding: a retired row is not something a run may post as.
            // `packagings.tallyItemIncludingArchived` and
            // `itemIncludingArchived` are the ARCHIVED-INCLUSIVE pair, read
            // ONLY by separateProductRows(), which reports the stored columns
            // themselves and so must be able to name a retired row at either
            // end. Four constant queries on `items`; dropping any one turns a
            // list into a query per row.
            ->with([
                'item',
                'itemIncludingArchived',
                'packagings.tallyItem',
                'packagings.tallyItemIncludingArchived',
            ])
            // The workspace's own order, so the two lists read the same way.
            ->orderBy('source_product_name')
            ->orderBy('cavities')
            ->orderBy('id')
            ->get();

        $rows = [
            // First: the packings the decision says are a different PRODUCT.
            // A packing listed here is being configured under the wrong row,
            // so its identity/ambiguity gaps below are questions about a row
            // that should not carry the packing at all — the reader is told
            // that before being asked to link anything. It does not REPLACE
            // those rows; the same packing may legitimately appear again in
            // each list beneath, each time about a different question.
            ...$this->separateProductRows($standards),
            ...$this->identityRows($standards),
            ...$this->ambiguityRows($standards),
            ...$this->provisionalSkuRows(),
        ];

        return ['rows' => $rows];
    }

    // ---- (a) the packing that is a separate product ---------------------------

    /**
     * Every ACTIVE configuration row where a packing carries a non-null
     * Tally identity of its own that names a DIFFERENT item from the product
     * its standard is attached to — the pair DEC-20260821-001 says must be
     * two finished products.
     *
     * ONE JUDGMENT, NOT A SECOND COPY OF IT.
     * `ProductVariantService::identityConflictsWithProduct()` is the same
     * predicate the Start Batch guard and both packaging identity writers
     * ask, and it is asked here rather than restated: the floor, the
     * configuration writers and this evidence list must never be able to
     * disagree about what the conflict IS. Both compliant answers are
     * therefore compliant here too, and produce no row — a null `item_id`
     * (INHERITANCE, most live rows: the packing posts as its product), and
     * an `item_id` equal to the product's (one identity stated twice).
     *
     * SCOPE IS THE REVIEW'S OWN: the `$standards` already loaded for the
     * other lists, and their `packagings` relation as it comes — which
     * excludes soft-deleted PACKAGINGS. The write-side mirror
     * (`firstPackagingConflictingWithProduct`) reads trashed packagings on
     * purpose, because an archived row can be un-archived into a conflict;
     * this list must not, because an archived packing is not something a
     * person is being asked to look at today.
     *
     * AN ARCHIVED ITEM IS THE OTHER THING ENTIRELY, and the distinction is
     * the reason this paragraph is two. The packing here is ACTIVE and its
     * `item_id` is set; only the ITEM ROW it names may be retired. That is a
     * row a person is very much being asked to look at, so the identity is
     * resolved through `tallyItemIncludingArchived` and named. Eager-loaded
     * with the rest (review()), so this adds one constant query, never one
     * per row.
     *
     * READ-ONLY, AND NOT A REFUSAL. These rows already exist and may already
     * have posted vouchers. Nothing here clears an identity, proposes a
     * candidate, or blocks anything — the forward guard shipped separately
     * and only governs NEW writes. What a completed run posted under is held
     * elsewhere and untouched by this list either way: frozen on the entry
     * (`shift_production_entries.finished_item_id`) and in the queued
     * voucher's own trail.
     *
     * @param  Collection<int, ProductionStandard>  $standards
     * @return list<array<string, mixed>>
     */
    private function separateProductRows(Collection $standards): array
    {
        $rows = [];

        foreach ($standards as $standard) {
            // ARCHIVED ROWS INCLUDED, and for the same reason the packing's
            // identity is read that way below: the predicate judges
            // `standards.item_id`, so the display has to be able to name what
            // that column says. A product item can be retired while the
            // standards pointing at it keep their `item_id`, and the hiding
            // relation would render "under the product no Tally identity"
            // over a product that is plainly recorded. Used ONLY to shape
            // `product_item` on this kind — `identityRows()` and
            // `ambiguityRows()` below keep reading `$standard->item`, because
            // a retired product genuinely IS absent for the questions they
            // ask (what a run would post as).
            $product = $standard->itemIncludingArchived;

            foreach ($standard->packagings as $packaging) {
                // The COLUMNS, not the loaded relations: `item_id` is the
                // relation the decision is about, and it is still the answer
                // when an item row is trashed and its relation reads null.
                if (! ProductVariantService::identityConflictsWithProduct(
                    $packaging->item_id === null ? null : (int) $packaging->item_id,
                    $standard->item_id === null ? null : (int) $standard->item_id,
                )) {
                    continue;
                }

                $rows[] = [
                    ...$this->row(
                        self::KIND_PACKAGING_SEPARATE_PRODUCT,
                        $standard,
                        $packaging,
                        // The row is ABOUT the packing's OWN stored identity
                        // — the different item it names, which is what makes
                        // it a separate product. Read straight off the
                        // relation rather than through identityFor(), and
                        // ARCHIVED ROWS INCLUDED, for one reason: the
                        // predicate above judged the COLUMN, so the display
                        // has to be able to name what the column says. Item
                        // soft-deletes and archiving one does not blank the
                        // packagings pointing at it, so identityFor() —
                        // rightly built on the hiding relation — returns null
                        // for a retired item and this row would read "posts
                        // as no Tally identity" over an identity that is
                        // plainly set. No fallback is lost: the predicate is
                        // false unless `item_id` is non-null, so identityFor()
                        // could never have reached its product fallback here.
                        $packaging->tallyItemIncludingArchived,
                        // The `missing` vocabulary is unchanged (P5-06: a new
                        // word there is a contract change for two screens).
                        // This row's own fact is the KIND, and it says nothing
                        // about counts or identities being absent.
                        [],
                        null,
                        // NO CANDIDATES. Every candidate this screen offers is
                        // something to LINK, and linking is the write the
                        // decision withdrew. The item that closes this row is a
                        // separate PRODUCT, which is not on offer here at all.
                        [],
                        self::FIX_SEPARATE_PRODUCT,
                    ),
                    // Added only on this kind, never on the three that predate
                    // it: their payloads are a contract two screens already
                    // read, and a key appearing on them would be a change to
                    // rows this PR does not touch. The reader needs BOTH ends
                    // of the relation to see the conflict — the product the
                    // packing sits under, beside the item it actually posts as.
                    'product_item' => $product === null ? null : [
                        'id' => (int) $product->id,
                        'sku' => (string) $product->sku,
                        'name' => (string) $product->name,
                    ],
                ];
            }
        }

        return $rows;
    }

    // ---- (b) no Tally identity -----------------------------------------------

    /**
     * @param  Collection<int, ProductionStandard>  $standards
     * @return list<array<string, mixed>>
     */
    private function identityRows(Collection $standards): array
    {
        $rows = [];

        foreach ($standards as $standard) {
            $product = $standard->item;
            $inheritors = 0;

            foreach ($standard->packagings as $packaging) {
                if ($packaging->item_id === null) {
                    $inheritors++;
                }

                $identity = $this->variants->identityFor($packaging, $product);

                if ($this->variants->hasTallyIdentity($identity)) {
                    continue;
                }

                $status = $this->variants->packagingStatus($packaging, $standard, $product);

                $rows[] = $this->row(
                    self::KIND_PACKAGING_NO_IDENTITY,
                    $standard,
                    $packaging,
                    $identity,
                    $status['missing'],
                    $status['ambiguity'],
                    $this->candidatesByNames([
                        $standard->source_product_name,
                        $product?->name,
                        $identity?->name,
                    ]),
                    self::FIX_PACKAGING_ITEM,
                );
            }

            // The product's own identity is missing and NOTHING above carries
            // that gap — no packaging inherits it — so the standard says it
            // once itself. (When a packaging inherits, its row already says
            // it, and the fix — link the packaging, or attach the product's
            // item — is the same person's next click either way.)
            if ($inheritors === 0 && ! $this->variants->hasTallyIdentity($product)) {
                $rows[] = $this->row(
                    self::KIND_PACKAGING_NO_IDENTITY,
                    $standard,
                    null,
                    $product,
                    [ProductVariantService::MISSING_TALLY_IDENTITY],
                    null,
                    $this->candidatesByNames([$standard->source_product_name, $product?->name]),
                    self::FIX_ATTACH_ITEM,
                );
            }
        }

        return $rows;
    }

    // ---- (c) shared Tally name -----------------------------------------------

    /**
     * @param  Collection<int, ProductionStandard>  $standards
     * @return list<array<string, mixed>>
     */
    private function ambiguityRows(Collection $standards): array
    {
        $rows = [];

        foreach ($standards as $standard) {
            $product = $standard->item;

            foreach ($standard->packagings as $packaging) {
                $identity = $this->variants->identityFor($packaging, $product);
                $ambiguity = $this->variants->ambiguityFor($identity);

                if ($ambiguity === null || $identity === null) {
                    continue;
                }

                $status = $this->variants->packagingStatus($packaging, $standard, $product);

                // The rows that share the name, as information: linking any
                // of them leaves the NAME shared, so no Link is offered — the
                // list says which catalogue rows a person has to tell apart
                // (a fixture or a retired item sharing the name is counted
                // by the resolver, but is not one a person could link).
                $candidates = $this->variants->sharingName($identity)
                    ->filter(fn (Item $item) => $this->isLinkable($item))
                    ->sortBy('id')
                    ->values()
                    ->all();

                $rows[] = $this->row(
                    self::KIND_PACKAGING_AMBIGUOUS,
                    $standard,
                    $packaging,
                    $identity,
                    $status['missing'],
                    $ambiguity,
                    $candidates,
                    self::FIX_NAME_AMBIGUITY,
                );
            }
        }

        return $rows;
    }

    // ---- (d) provisional SKUs ------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function provisionalSkuRows(): array
    {
        return Item::query()
            ->where('sku_provisional', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Item $item) => $this->row(
                self::KIND_ITEM_PROVISIONAL_SKU,
                null,
                null,
                $item,
                [],
                null,
                // The same-named Tally items beside it — the duplicates a
                // real SKU would have to tell apart — never the row itself.
                array_values(array_filter(
                    $this->candidatesByNames([$item->name]),
                    fn (Item $candidate) => (int) $candidate->id !== (int) $item->id,
                )),
                self::FIX_ITEM_SKU,
            ))
            ->all();
    }

    // ---- shaping -------------------------------------------------------------

    /**
     * @param  list<string>  $missing
     * @param  ?array{shared_name_count: int}  $ambiguity
     * @param  list<Item>  $candidates
     * @return array<string, mixed>
     */
    private function row(
        string $kind,
        ?ProductionStandard $standard,
        ?ProductionStandardPackaging $packaging,
        ?Item $item,
        array $missing,
        ?array $ambiguity,
        array $candidates,
        string $fixTarget,
    ): array {
        return [
            'kind' => $kind,
            'standard' => $standard === null ? null : [
                'id' => (int) $standard->id,
                'product' => (string) $standard->source_product_name,
            ],
            'packaging' => $packaging === null ? null : [
                'id' => (int) $packaging->id,
                'mode' => (string) $packaging->mode,
                'counts' => [
                    'nos_per_pouch' => $packaging->nos_per_pouch,
                    'pouches_per_box' => $packaging->pouches_per_box,
                    'nos_per_tray' => $packaging->nos_per_tray,
                    'trays_per_box' => $packaging->trays_per_box,
                    'nos_per_box' => $packaging->nos_per_box,
                ],
            ],
            // The item the row is ABOUT: the identity the packing resolves to
            // today (null when it resolves to nothing), or the provisional-SKU
            // item itself — or, on a separate-product row alone, the packing's
            // own stored identity, which may be an archived catalogue row.
            'item' => $item === null ? null : [
                'id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
            ],
            'missing' => $missing,
            'ambiguity' => $ambiguity,
            'candidates' => array_map(fn (Item $candidate) => [
                'id' => (int) $candidate->id,
                'sku' => (string) $candidate->sku,
                'name' => (string) $candidate->name,
                'guid' => $candidate->tally_stock_item_guid,
            ], $candidates),
            'fix_target' => $fixTarget,
        ];
    }

    /**
     * The linkable items whose normalised name equals any of the given
     * names (a fixture's "(LOCAL FIXTURE)" suffix stripped first), each once,
     * in catalogue order.
     *
     * @param  list<?string>  $names
     * @return list<Item>
     */
    private function candidatesByNames(array $names): array
    {
        $index = $this->linkableByName();
        $found = [];

        foreach ($names as $name) {
            $key = $this->normalise((string) $name);
            if ($key === '') {
                continue;
            }
            foreach ($index[$key] ?? [] as $item) {
                $found[(int) $item->id] = $item;
            }
        }

        $items = array_values($found);
        usort($items, fn (Item $a, Item $b) => [(string) $a->name, (int) $a->id] <=> [(string) $b->name, (int) $b->id]);

        return $items;
    }

    /**
     * Every item a person may link — Tally-pulled and not a fixture — grouped
     * by normalised name. Read once per review; the catalogue is a few
     * hundred rows, and the alternative (a query per row per name) is how a
     * review list of forty becomes forty queries.
     *
     * @return array<string, list<Item>>
     */
    private function linkableByName(): array
    {
        if ($this->linkable !== null) {
            return $this->linkable;
        }

        $index = [];

        $items = Item::query()
            ->whereNotNull('tally_stock_item_guid')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if (! $this->isLinkable($item)) {
                continue;
            }
            $index[$this->normalise((string) $item->name)][] = $item;
        }

        return $this->linkable = $index;
    }

    /**
     * Active, Tally-pulled and not a local fixture — the only rows a voucher
     * may ever name (and the only rows the identity requests accept), so the
     * only rows worth offering as a link.
     */
    private function isLinkable(Item $item): bool
    {
        return (bool) $item->is_active
            && $item->tally_stock_item_guid !== null
            && ! $item->isLocalFixture();
    }

    /**
     * Case-, trim- and whitespace-insensitive — the product-master import's
     * own rule (ProductionStandardImportService::normaliseName), so a name
     * that matched at import matches here — with the fixture suffix removed
     * so a fixture's name finds the real item of the same product.
     */
    private function normalise(string $value): string
    {
        $value = trim($value);

        if (str_ends_with($value, self::LOCAL_FIXTURE_NAME_SUFFIX)) {
            $value = trim(substr($value, 0, -strlen(self::LOCAL_FIXTURE_NAME_SUFFIX)));
        }

        return preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '';
    }
}
