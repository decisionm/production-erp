<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\TallySync\Services\LineMappingResolver;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * DEC-20260821-001's refusal, WORDED ONCE — the backend half of the
     * frontend's SEPARATE_PRODUCT_REQUIRED_DETAIL, and for the same reason
     * MISSING_VOCABULARY is worded once: a supervisor told one thing on the
     * floor and another in the office is the failure this module exists to
     * prevent.
     *
     * FIVE REFUSAL SURFACES repeat these two verbatim, each after its own
     * first sentence naming that case's parties. The two packaging writers
     * are counted once per LAYER, not once per route, because both layers
     * emit the message and a reader chasing the string needs both:
     *   - Start Batch — PackagingBelongsToSeparateProductException::make()
     *   - the two packaging writers at the REQUEST layer — the shared
     *     RefusesSeparateProductIdentity trait (POST/PUT save and the
     *     PATCH .../identity), so neither route is a door around the other
     *   - those same two writers at the SERVICE layer, behind the row lock —
     *     ProductionStandardPackagingService::refuseSeparateProduct()
     *   - the attach endpoint — ProductionStandardService::attachItem()
     *   - the importer — ProductionStandardImportService, which records the
     *     refusal per orphan row and skips only that one adoption
     *
     * A SIXTH site reads the same predicate and deliberately does NOT use
     * these strings: ConfigurationReviewService's packaging_separate_product
     * row. It is ADVISORY — it reports pairs that already exist rather than
     * refusing a write — so it carries its own listing copy instead.
     */
    public const SEPARATE_PRODUCT_REASON = 'A packing that posts as its own Tally stock item is a separate '
        .'finished product (DEC-20260821-001), not a second identity under this one.';

    /**
     * What to actually DO, and the reason it starts at Tally rather than at
     * the Add Item button: a finished item hand-created here carries no Tally
     * GUID, and an item without a GUID cannot post — so "create a product"
     * on its own produces a master that looks right and refuses at the
     * voucher. The real item arrives through the masters pull.
     */
    public const SEPARATE_PRODUCT_INSTRUCTION = 'Pull the Tally masters so that stock item is in the catalogue '
        .'(an item created by hand here carries no Tally GUID and cannot post), create or attach its production '
        .'standard, then select that product.';

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
     * one, else the product's. This is a READ, and it is unchanged: it is
     * still the rule completion freezes into finished_item_id, and it is
     * still how every already-posted voucher is explained (DEC-20260810-003,
     * superseded for NEW writes by DEC-20260821-001 — see
     * identityConflictsWithProduct() below). Null when neither exists.
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
     * THE ONE JUDGMENT DEC-20260821-001 ADDS: does this packing's OWN Tally
     * identity name a DIFFERENT item from the product it is packed under?
     *
     * Where Tally carries separate stock items for a product's pouch and tray
     * packings, the ERP must hold TWO finished-product masters, each mapped
     * one-to-one to its own Tally stock item — not two packaging identities
     * under one product. DEC-20260810-003 authorised the second shape; this
     * record withdraws that authority for anything written from now on.
     *
     * The two compliant answers, both of which this returns false for and
     * both of which must keep working:
     *
     *  - `$packagingItemId === null` — INHERITANCE. The packing posts as its
     *    product (identityFor() above). This is the majority of live rows and
     *    a guard that fired on it would stop the floor.
     *  - the two ids are EQUAL — one product, one Tally item, stated twice.
     *
     * `$productItemId === null` is also false, and deliberately: an
     * unattached standard (production_standards.item_id is nullable — an
     * import the matcher could not place) has no product to conflict with,
     * and its packing's own identity is the only postable identity it has.
     * Refusing there would block a configuration write on a row that breaks
     * no rule. On the batch path the product id is never null — the entry's
     * item_id is required — so nothing is waived there.
     *
     * Ids, not models, because the two configuration writers judge an
     * INCOMING item_id against a standard before any row exists to hold it,
     * and static because those judges are FormRequests: the rule is pure
     * arithmetic on two ids and must not drag this service's dependencies
     * into request validation.
     *
     * This says only whether the relation conflicts. Whether a conflicting
     * value may still be WRITTEN — an existing legacy row re-saved with the
     * identity it already carries — is the writers' own exemption, not this
     * predicate's: fold it in here and the Start Batch guard would inherit
     * it and let a legacy conflicting packing start new batches.
     */
    public static function identityConflictsWithProduct(?int $packagingItemId, ?int $productItemId): bool
    {
        return $packagingItemId !== null
            && $productItemId !== null
            && $packagingItemId !== $productItemId;
    }

    /**
     * THE PRODUCT-SIDE MIRROR of the predicate above, and the reason it had
     * to exist: the same forbidden relation can be built from EITHER end.
     *
     * THE COMPOSED RULE, stated plainly because the two halves read as a
     * contradiction otherwise. The configuration writers deliberately PERMIT
     * a distinct identity on a packing while its standard is unattached
     * (`production_standards.item_id` null — an import the matcher could not
     * place): there is no product to conflict with yet, and refusing there
     * would block a write that breaks no rule. That permission is precisely
     * the precondition for the hole. So the other end has to close it: once
     * a packing carries an identity of its own, the standard may only ever be
     * attached to THAT identity. You may say it while unattached; you may
     * then only attach to what you said.
     *
     * Returns the first packing that would be contradicted by attaching
     * `$candidateProductItemId`, or null when the attachment is compliant.
     *
     * TRASHED ROWS COUNT. Packagings soft-delete, and Activate re-validates
     * nothing, so without them archive-B → attach-to-A → activate-B is a
     * three-step construction of a NEW mismatch. The converse is NOT guarded
     * and should not be: archiving and re-activating a packing that was
     * already conflicting under an already-attached standard is maintenance
     * of a legacy row, not a new write.
     *
     * THIS IS A READ THAT A WRITER ACTS ON, so it must run inside the
     * writer's own transaction, after that writer has taken the FOR UPDATE
     * on the standard row — the same lock ProductionStandardPackagingService
     * takes. Called outside one, it answers about a state another request may
     * already be changing.
     *
     * AND IT IS ITSELF A LOCKING READ, which is not belt-and-braces on top
     * of that. The standard's row lock buys mutual exclusion; it does not
     * buy this read freshness. Live runs MySQL at the server default
     * REPEATABLE READ (nothing pins an isolation level), where a plain
     * SELECT answers from the snapshot the transaction took at its FIRST
     * read — and a writer reaches this method well after that: the importer
     * has already indexed the catalogue and matched its variants. A packing
     * identity another transaction committed in between would be INVISIBLE,
     * the conflict check would pass on stale rows and the forbidden pair
     * would commit despite the lock. A locking read reads the latest
     * committed version, which is the whole reason for the FOR UPDATE here.
     *
     * Narrow and in the same order as everything else: scoped to one
     * standard's packagings, taken after that standard's row lock — the
     * order ProductionStandardPackagingService already writes in, so there
     * is no inversion to deadlock on.
     *
     * It does NOT carry the legacy no-op exemption. A writer re-stating the
     * item a standard is ALREADY attached to is maintaining history, not
     * making a new assignment, and each writer applies that exemption itself
     * — for the same reason identityConflictsWithProduct() does not fold it
     * in: a shared predicate that forgave the legacy case would forgive it
     * everywhere, including where it must not be forgiven.
     */
    public static function firstPackagingConflictingWithProduct(
        ProductionStandard $standard,
        ?int $candidateProductItemId,
    ): ?ProductionStandardPackaging {
        if ($candidateProductItemId === null) {
            return null;
        }

        return self::conflictingPackagingQuery($standard, $candidateProductItemId)->first();
    }

    /**
     * The query above, in its own method so the lock it carries is a thing a
     * test can hold and assert — SQLite drops FOR UPDATE, so a lock that
     * only ever exists mid-statement cannot be pinned any other way.
     *
     * @return Builder<ProductionStandardPackaging>
     */
    private static function conflictingPackagingQuery(
        ProductionStandard $standard,
        int $candidateProductItemId,
    ): Builder {
        return ProductionStandardPackaging::withTrashed()
            ->where('production_standard_id', $standard->getKey())
            ->whereNotNull('item_id')
            ->where('item_id', '!=', $candidateProductItemId)
            ->orderBy('id')
            ->lockForUpdate();
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
