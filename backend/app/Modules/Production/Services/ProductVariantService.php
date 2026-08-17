<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\TallySync\Services\LineMappingResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * The variant tree of one product — item → standards → packagings — and the
 * ONE judgment of what each node is still missing (Phase 5, P5-02 / P5-06).
 *
 * WHY ONE PLACE. The Start Batch preview, the standards workspace and the
 * configuration review all have to say "this packing is incomplete: X
 * missing", and until now each screen would have derived its own X from the
 * raw columns — three vocabularies for one fact, and a supervisor told one
 * thing on the floor and another in the office. The words are computed
 * here and every surface REPEATS them: `configuration_status.missing`
 * carries names from MISSING_VOCABULARY, in that order, and nothing else.
 *
 * WHAT "MISSING" MEANS, per node:
 *
 *   packaging  — `counts`: the row cannot run a batch
 *                (ProductionStandardPackaging::isComplete(): pieces per box
 *                plus the inner count its mode calls for). `tally_identity`:
 *                the identity this packing WOULD POST AS — its own item, else
 *                the product's (DEC-20260810-003) — is null, carries no Tally
 *                GUID, or is a LOCAL- fixture. A fixture IS counted here,
 *                unlike in the readiness gate's gap list, because this
 *                surface exists to get a real Tally item linked, and a
 *                fixture is precisely a product still waiting for one.
 *   standard   — the run figures in the SAME precedence the readiness gate
 *                and the estimation use (standard outranks item master):
 *                `cavities`, `unit_weight`, `cycle_time` are missing only
 *                when NEITHER side has them, so this list never contradicts
 *                the gate on the same screen. `packaging` when the standard
 *                has no packing row at all. `tally_identity` when the
 *                product's own item — the fallback identity — is null,
 *                GUID-less or a fixture. Plus the union of its packagings'
 *                missing, so a word said on a leaf is said on the branch.
 *   product    — `standard` when there is none, else the union of the
 *                standards'; `tally_identity` when the item itself lacks one.
 *   run        — (runStatus, Phase 5.5) the standard's own figures plus the
 *                words of the ONE packaging a batch froze — never a sibling
 *                packaging's — for the entry resource's `configuration_gaps`.
 *
 * AMBIGUITY is reported beside `missing`, never inside it. Two ERP rows
 * sharing one name (LineMappingResolver's `ambiguous` state — items.name
 * carries no unique index) is a catalogue problem a person must settle, not
 * a gap on this packing: the identity IS set and Tally will match one of the
 * rows by name. It rides as `{shared_name_count}` so the review surface can
 * list it, and is null in every other case. The count comes from TallySync's
 * own resolver — read-only, through its service — so this screen and the
 * voucher preview can never disagree about whether a name is ambiguous.
 *
 * `is_complete` on a packaging is a DIFFERENT, older fact — "runnable" (the
 * counts alone) — and is what the Start Batch picker disables on. It is left
 * exactly as it was; configuration_status is additive beside it.
 */
class ProductVariantService
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_INCOMPLETE = 'incomplete';

    public const MISSING_STANDARD = 'standard';

    public const MISSING_CAVITIES = 'cavities';

    public const MISSING_UNIT_WEIGHT = 'unit_weight';

    public const MISSING_CYCLE_TIME = 'cycle_time';

    public const MISSING_PACKAGING = 'packaging';

    public const MISSING_COUNTS = 'counts';

    public const MISSING_TALLY_IDENTITY = 'tally_identity';

    /**
     * Every word `missing` may carry, in the order it carries them. The
     * frontend repeats these verbatim (P5-06); renaming one is a contract
     * change for two screens.
     *
     * @var list<string>
     */
    public const MISSING_VOCABULARY = [
        self::MISSING_STANDARD,
        self::MISSING_CAVITIES,
        self::MISSING_UNIT_WEIGHT,
        self::MISSING_CYCLE_TIME,
        self::MISSING_PACKAGING,
        self::MISSING_COUNTS,
        self::MISSING_TALLY_IDENTITY,
    ];

    public function __construct(
        private readonly ProductionStandardResolver $resolver,
        private readonly LineMappingResolver $mappings,
    ) {}

    /**
     * The whole tree for one product, as GET production/products/{item}/variants
     * returns it.
     *
     * @return array{
     *     item: array{id: int, sku: string, name: string, guid: ?string, sku_provisional: bool},
     *     standards: list<array<string, mixed>>,
     *     configuration_status: array{complete: bool, missing: list<string>},
     * }
     */
    public function tree(Item $item): array
    {
        // The SAME variant source the Start Batch preview reads
        // (ProductionStandardResolver::variantsFor — approved first, then
        // by cavities and cycle time, packagings and their identities
        // loaded), so the two screens list the same rows in the same order.
        $standards = $this->resolver->variantsFor($item->id);

        $standardBlocks = $standards->map(fn (ProductionStandard $standard) => $this->standardBlock($standard, $item))->values();

        return [
            'item' => [
                'id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'guid' => $item->tally_stock_item_guid,
                'sku_provisional' => (bool) $item->sku_provisional,
            ],
            'standards' => $standardBlocks->all(),
            'configuration_status' => $this->productStatus($item, $standards),
        ];
    }

    /**
     * One standard with its packagings, each carrying identity and status.
     *
     * @return array<string, mixed>
     */
    private function standardBlock(ProductionStandard $standard, Item $item): array
    {
        $packagings = $standard->packagings->map(function (ProductionStandardPackaging $packaging) use ($standard, $item) {
            $status = $this->packagingStatus($packaging, $standard, $item);

            return [
                'id' => (int) $packaging->id,
                'mode' => (string) $packaging->mode,
                'label' => $packaging->label(),
                'nos_per_pouch' => $packaging->nos_per_pouch,
                'pouches_per_box' => $packaging->pouches_per_box,
                'nos_per_tray' => $packaging->nos_per_tray,
                'trays_per_box' => $packaging->trays_per_box,
                'nos_per_box' => $packaging->nos_per_box,
                'is_default' => (bool) $packaging->is_default,
                'is_complete' => $packaging->isComplete(),
                'tally_item' => $this->tallyItem($packaging->item_id === null ? null : $packaging->tallyItem),
                // Which item this packing WILL post as, resolved — its own,
                // else the product's — so the screen prints the fallback
                // without re-deriving the rule.
                'resolved_tally_item' => $this->tallyItem($this->identityFor($packaging, $item)),
                'uses_product_identity' => $packaging->item_id === null,
                // The status, flat (P5-02's shape) AND nested under the one
                // key every other surface reads — the same values, so a
                // reader coded against either name gets the same answer.
                'state' => $status['state'],
                'missing' => $status['missing'],
                'ambiguity' => $status['ambiguity'],
                'configuration_status' => $status,
            ];
        })->values();

        return [
            'id' => (int) $standard->id,
            'label' => $standard->variantLabel(),
            'source_product_name' => $standard->source_product_name,
            'cavities' => $standard->cavities,
            'unit_weight_grams' => $standard->unit_weight_grams,
            'cycle_time' => $standard->cycle_time,
            'status' => $standard->status,
            'unresolved_reason' => $standard->unresolved_reason,
            'packagings' => $packagings->all(),
            'configuration_status' => $this->standardStatus($standard, $item),
        ];
    }

    // ---- the status judgments (shared with the preview) -----------------------

    /**
     * What one packaging is missing, and whether its identity's name is
     * ambiguous. $item is the product the standard belongs to (the fallback
     * identity); null when the standard is not attached to any item.
     *
     * @return array{state: string, missing: list<string>, ambiguity: ?array{shared_name_count: int}}
     */
    public function packagingStatus(ProductionStandardPackaging $packaging, ProductionStandard $standard, ?Item $item): array
    {
        $missing = $this->packagingMissing($packaging, $item);

        return [
            'state' => $missing === [] ? self::STATE_COMPLETE : self::STATE_INCOMPLETE,
            'missing' => $this->ordered($missing),
            'ambiguity' => $this->ambiguityFor($this->identityFor($packaging, $item)),
        ];
    }

    /**
     * The words alone — what one packaging is missing, WITHOUT the ambiguity
     * judgment. The standard's and the run's verdicts (standardStatus,
     * runStatus) only ever carried `missing`; they used to reach it through
     * packagingStatus() and so paid for an ambiguity lookup they discarded —
     * a LineMappingResolver::item(name) query per packaging, per row of a
     * list, whose memo dies with each per-row service instance (Phase 5.5
     * fix loop: +2 items queries per Completed Today row). Same rule, same
     * words, same order; only packagingStatus() — the per-packaging block
     * that REPORTS ambiguity — still asks for it.
     *
     * @return list<string>
     */
    private function packagingMissing(ProductionStandardPackaging $packaging, ?Item $item): array
    {
        $missing = [];

        if (! $packaging->isComplete()) {
            $missing[] = self::MISSING_COUNTS;
        }

        if (! $this->hasTallyIdentity($this->identityFor($packaging, $item))) {
            $missing[] = self::MISSING_TALLY_IDENTITY;
        }

        return $missing;
    }

    /**
     * What one standard is missing — its own figures, its packing, its
     * product's identity — plus every word its packagings say.
     *
     * @return array{state: string, missing: list<string>}
     */
    public function standardStatus(ProductionStandard $standard, ?Item $item): array
    {
        $missing = $this->standardFigureGaps($standard, $item);

        if ($standard->packagings->isEmpty()) {
            $missing[] = self::MISSING_PACKAGING;
        }

        if (! $this->hasTallyIdentity($item)) {
            $missing[] = self::MISSING_TALLY_IDENTITY;
        }

        foreach ($standard->packagings as $packaging) {
            $missing = [...$missing, ...$this->packagingMissing($packaging, $item)];
        }

        return [
            'state' => $missing === [] ? self::STATE_COMPLETE : self::STATE_INCOMPLETE,
            'missing' => $this->ordered($missing),
        ];
    }

    /**
     * What ONE RUN was missing — the judgment for a batch, as opposed to the
     * standard's (Phase 5.5, WS-B: the entry resource's `configuration_gaps`).
     *
     * A batch runs against one standard AND one packaging, so its gaps are
     * the standard's own figures plus the words of THAT packaging — not the
     * union over every packaging the standard has, which is what
     * standardStatus() says and rightly so for the standard. A tray run on a
     * complete tray row is not incomplete because a sibling pouch row still
     * lacks its counts. The identity that matters is the one this run posts
     * as (identityFor: the packaging's own item, else the product's —
     * DEC-20260810-003), so a real item on the packing completes a run even
     * when the product's own row is a fixture; the product's identity is
     * judged only when no packaging carries the question.
     *
     * A run that froze no packaging (a batch started before the id was
     * frozen, or a standard with no packing rows) falls back to the
     * standard's judgment, and a run with no standard says so — plus the
     * product's identity, the only other thing such a run can post with.
     * Same vocabulary, same order; no ambiguity block, because a batch has
     * nowhere to send a person to settle one — and none is computed, so a
     * list of runs costs no name lookups (packagingMissing).
     *
     * FROZEN AT START. startBatch() writes this verdict into the entry's
     * config_snapshot['configuration_gaps'] and the handover child copies
     * its parent's; the entry resource reads the snapshot first
     * (CompletionDefaultsService) and computes live only for a run started
     * before the snapshot existed. So a master fixed later never restates
     * a finished batch's "config incomplete" — the same reason the
     * calculation_version stamp exists.
     *
     * @return array{state: string, missing: list<string>}
     */
    public function runStatus(?ProductionStandard $standard, ?ProductionStandardPackaging $packaging, ?Item $item): array
    {
        if ($standard === null) {
            $missing = [self::MISSING_STANDARD];

            if (! $this->hasTallyIdentity($item)) {
                $missing[] = self::MISSING_TALLY_IDENTITY;
            }

            return [
                'state' => self::STATE_INCOMPLETE,
                'missing' => $this->ordered($missing),
            ];
        }

        if ($packaging === null) {
            return $this->standardStatus($standard, $item);
        }

        $missing = $this->standardFigureGaps($standard, $item);
        $missing = [...$missing, ...$this->packagingMissing($packaging, $item)];

        return [
            'state' => $missing === [] ? self::STATE_COMPLETE : self::STATE_INCOMPLETE,
            'missing' => $this->ordered($missing),
        ];
    }

    /**
     * The product as a whole: complete only when every standard is.
     *
     * @param  Collection<int, ProductionStandard>  $standards
     * @return array{complete: bool, missing: list<string>}
     */
    public function productStatus(Item $item, Collection $standards): array
    {
        $missing = [];

        if ($standards->isEmpty()) {
            $missing[] = self::MISSING_STANDARD;
        }

        if (! $this->hasTallyIdentity($item)) {
            $missing[] = self::MISSING_TALLY_IDENTITY;
        }

        foreach ($standards as $standard) {
            $missing = [...$missing, ...$this->standardStatus($standard, $item)['missing']];
        }

        return [
            'complete' => $missing === [],
            'missing' => $this->ordered($missing),
        ];
    }

    /**
     * The standard's own run figures — cavities, unit weight, cycle time —
     * that neither it nor the item master supplies. Shared by the standard's
     * judgment and the run's, so the two can never disagree about a figure.
     *
     * The gate's precedence: the factory standard outranks the item master,
     * and a figure present on either side is not a gap because the run will
     * find it (ProductReadinessService::configurationGaps).
     *
     * @return list<string>
     */
    private function standardFigureGaps(ProductionStandard $standard, ?Item $item): array
    {
        $missing = [];

        if (! $this->positive($standard->cavities ?? $item?->standard_cavities)) {
            $missing[] = self::MISSING_CAVITIES;
        }
        if (! $this->positive($standard->unit_weight_grams ?? $item?->nominal_weight_grams)) {
            $missing[] = self::MISSING_UNIT_WEIGHT;
        }
        if (! $this->positive($standard->cycle_time ?? $item?->standard_cycle_time)) {
            $missing[] = self::MISSING_CYCLE_TIME;
        }

        return $missing;
    }

    // ---- identity ------------------------------------------------------------

    /**
     * The item this packing WILL post as: its own Tally identity when it has
     * one, else the product's — the same rule completion freezes into
     * finished_item_id (DEC-20260810-003). Null when neither exists.
     */
    public function identityFor(ProductionStandardPackaging $packaging, ?Item $product): ?Item
    {
        if ($packaging->item_id !== null) {
            // The relation is loaded by every caller (variantsFor eager-loads
            // packagings.tallyItem); loadMissing keeps a bare row honest
            // without re-querying a loaded one.
            $packaging->loadMissing('tallyItem');

            return $packaging->tallyItem;
        }

        return $product;
    }

    /**
     * Whether an item is something a Tally voucher can name: a row that
     * carries a Tally GUID and is not a local rehearsal fixture.
     */
    public function hasTallyIdentity(?Item $item): bool
    {
        return $item !== null
            && $item->tally_stock_item_guid !== null
            && ! $item->isLocalFixture();
    }

    /**
     * The identity block every surface prints — id · sku · name · guid —
     * or null when there is no item.
     *
     * @return ?array{id: int, sku: string, name: string, guid: ?string}
     */
    public function tallyItem(?Item $item): ?array
    {
        if ($item === null) {
            return null;
        }

        return [
            'id' => (int) $item->id,
            'sku' => (string) $item->sku,
            'name' => (string) $item->name,
            'guid' => $item->tally_stock_item_guid,
        ];
    }

    /**
     * `{shared_name_count}` when more than one ERP item carries this
     * identity's name — LineMappingResolver's judgment, not a second one —
     * else null.
     *
     * @return ?array{shared_name_count: int}
     */
    public function ambiguityFor(?Item $identity): ?array
    {
        if ($identity === null) {
            return null;
        }

        $state = $this->mappings->item((string) $identity->name);

        if ($state['state'] !== LineMappingResolver::STATE_AMBIGUOUS) {
            return null;
        }

        return ['shared_name_count' => (int) $state['shared_count']];
    }

    /**
     * EVERY ERP item carrying this identity's name — the rows an ambiguity is
     * made of, from the same memoised lookup ambiguityFor() judged on, so
     * the count and the list can never disagree. Empty when there is no
     * identity.
     *
     * @return Collection<int, Item>
     */
    public function sharingName(?Item $identity): Collection
    {
        if ($identity === null) {
            return new Collection;
        }

        return $this->mappings->itemCandidates((string) $identity->name);
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * De-duplicated, in vocabulary order — so two packagings both missing
     * counts say `counts` once on the standard, and the words always read
     * in the same order wherever they appear.
     *
     * @param  list<string>  $missing
     * @return list<string>
     */
    private function ordered(array $missing): array
    {
        return array_values(array_filter(
            self::MISSING_VOCABULARY,
            fn (string $word) => in_array($word, $missing, true),
        ));
    }

    private function positive(mixed $value): bool
    {
        return $value !== null && (float) $value > 0;
    }
}
