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
 * THREE KINDS OF ROW, in this order:
 *
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
 * EVERY ROW OFFERS CANDIDATES — the existing Tally items a person could
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
            ->with(['item', 'packagings.tallyItem'])
            // The workspace's own order, so the two lists read the same way.
            ->orderBy('source_product_name')
            ->orderBy('cavities')
            ->orderBy('id')
            ->get();

        $rows = [
            ...$this->identityRows($standards),
            ...$this->ambiguityRows($standards),
            ...$this->provisionalSkuRows(),
        ];

        return ['rows' => $rows];
    }

    // ---- (a) no Tally identity -----------------------------------------------

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

    // ---- (b) shared Tally name -----------------------------------------------

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

    // ---- (c) provisional SKUs ------------------------------------------------

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
            // item itself.
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
