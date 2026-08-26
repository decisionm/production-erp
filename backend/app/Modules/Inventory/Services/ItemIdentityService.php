<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\TallySync\Services\LineMappingResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * WHAT LOOKS WRONG WITH THE ITEM MASTER — derived on every read, stored
 * nowhere, and authorised to change nothing.
 *
 * The catalogue is ~644 rows that arrived from three directions (a Tally
 * masters pull, a product-standard import, and people typing) and no two of
 * them agree about what an item's identity is. This class is the ONE place
 * that says what looks wrong, so the answer is the same on a dashboard
 * badge, a filtered list and one item's own page.
 *
 * ## The rule this class lives under
 *
 * IT WARNS. It does not block, merge, rename, reclassify, archive or write
 * anything at all — there is no write path in this file. That is not
 * caution, it is the recorded state of the questions:
 *
 *   * Q43 — does a duplicate master name BLOCK approval, or only warn? OPEN.
 *   * Q59 — which categories may each document use? OPEN.
 *   * Q60 — which ItemCategory does each Tally stock group map to? OPEN, and
 *     its two hardest cases (`Caps & Closures`, 132 items, and `Scrap`, 16)
 *     are exactly the ones this class refuses to suggest for.
 *
 * AGENTS.md: an agent proposes, the owner decides; a missing figure is
 * reported missing, never interpolated. So every class below is a
 * SURFACE for a decision, never the decision.
 *
 * ## Derived, and why there is no conflicts table
 *
 * Every set is recomputed from the masters as they stand — the same choice
 * {@see LineMappingResolver} made and for the same reason: a duplicate name
 * fixed this morning must read as fixed this afternoon, and a stored verdict
 * is a second truth to keep in step. The sets are memoised for the LIFETIME
 * OF ONE INSTANCE (one request), because a paginated list asks
 * {@see warningsFor()} once per row.
 *
 * ## Cost
 *
 * ~10 queries for the whole sweep regardless of how many warnings are asked
 * about, plus one `id, name, uom, …` pass over the non-deleted masters for
 * the two name folds. At 644 rows that is cheap; if the catalogue grows an
 * order of magnitude the name folds are the first thing to move into SQL.
 */
class ItemIdentityService
{
    /** Page size for the warnings list, and the ceiling a caller may ask for. */
    public const PER_PAGE_DEFAULT = 25;

    public const PER_PAGE_MAX = 200;

    /** A suggestion the evidence supports plainly. */
    public const CONFIDENCE_FIRM = 'firm';

    /** A suggestion that is a judgement call — shown, and marked as one. */
    public const CONFIDENCE_LOW = 'low';

    /**
     * TALLY STOCK GROUP -> SUGGESTED CATEGORY. A SUGGESTION, NOT A DECISION.
     *
     * Keyed by the group name folded through {@see foldGroupName()} (lower
     * case, punctuation-preserving, whitespace collapsed) so 'BOPP TAPE' and
     * 'Bopp Tape' are the same key. Evidence: the stock-master XML read on
     * 26-Aug-2026, and the group list in Q60.
     *
     * A `null` VALUE IS A DELIBERATE REFUSAL TO SUGGEST, and it is not the
     * same thing as a group missing from this table:
     *
     *   * `Caps & Closures` (132 items — the single largest group) is what
     *     Q60(a) is ABOUT. A cap is fitted TO the bottle and a measuring cup
     *     is packed WITH it; `raw_material` and `packing_material` are both
     *     defensible and only the owner may pick. Suggesting either here
     *     would be this repo's oldest mistake — a derived factory value
     *     reaching a screen as if it were a fact (PR #128).
     *   * `Scrap` (16 items) is Q60(b). Scrap is PRODUCED, not purchased
     *     (FC-02), and the consequence decides it: `sellable()` is true for
     *     `finished_good` alone, so whether scrap may ever go on a sales
     *     order IS the question. No suggestion.
     *   * A group NOT IN THIS TABLE gets nothing either, and deliberately so:
     *     there is no walk up to a parent group. A new Tally child group
     *     would otherwise inherit a suggestion nobody has evidence for.
     *
     * @var array<string, array{category: ?ItemCategory, confidence: ?string}>
     */
    private const GROUP_SUGGESTIONS = [
        // --- raw material -------------------------------------------------
        'raw material' => ['category' => ItemCategory::RawMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        'pet' => ['category' => ItemCategory::RawMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        // Masterbatch is the colourant dosed into a run, so it is an input —
        // but Q60 has not said so, and "input" is not automatically "raw
        // material" in a taxonomy that also has consumables. LOW.
        'master batch' => ['category' => ItemCategory::RawMaterial, 'confidence' => self::CONFIDENCE_LOW],

        // --- packing material ---------------------------------------------
        'packing material' => ['category' => ItemCategory::PackingMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        'carton box' => ['category' => ItemCategory::PackingMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        'tray' => ['category' => ItemCategory::PackingMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        'bopp tape' => ['category' => ItemCategory::PackingMaterial, 'confidence' => self::CONFIDENCE_FIRM],
        'shrink rolls' => ['category' => ItemCategory::PackingMaterial, 'confidence' => self::CONFIDENCE_FIRM],

        // --- finished goods: the parent group and each of its children,
        //     named one by one because that is what the evidence lists.
        'finished goods' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'amber pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'clear pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'green pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'liquor pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'milk white pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'orange pet bottle' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'tablet container' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],
        'hdpe bottles & container' => ['category' => ItemCategory::FinishedGood, 'confidence' => self::CONFIDENCE_FIRM],

        // --- the two Q60 refuses to answer, said out loud -------------------
        'caps & closures' => ['category' => null, 'confidence' => null],
        'scrap' => ['category' => null, 'confidence' => null],
    ];

    /**
     * item id => list of warning keys, computed once per instance.
     *
     * @var array<int, list<string>>|null
     */
    private ?array $byItem = null;

    /**
     * warning key => list of item ids, computed once per instance.
     *
     * @var array<string, list<int>>|null
     */
    private ?array $byWarning = null;

    public function __construct(private readonly LineMappingResolver $mappings) {}

    // ---- the public surface --------------------------------------------------

    /**
     * HOW THE CATALOGUE IS DOING — one count per warning class, in the
     * enum's declared order so the frontend's badge row never reshuffles.
     *
     * @return array{items: int, items_with_any_warning: int, warnings: list<array{class: string, label: string, count: int}>}
     */
    public function health(): array
    {
        $byWarning = $this->sets()['by_warning'];

        $warnings = [];
        foreach (ItemIdentityWarning::cases() as $case) {
            $warnings[] = [
                'class' => $case->value,
                'label' => $case->label(),
                'count' => count($byWarning[$case->value] ?? []),
            ];
        }

        return [
            'items' => Item::query()->count(),
            'items_with_any_warning' => count($this->sets()['by_item']),
            'warnings' => $warnings,
        ];
    }

    /**
     * EVERY WARNING ONE ITEM TRIPS, each with the sentence that explains it
     * to a person. Empty is the ordinary answer.
     *
     * @return list<array{class: string, label: string, note: string}>
     */
    public function warningsFor(Item $item): array
    {
        $keys = $this->sets()['by_item'][(int) $item->getKey()] ?? [];

        return array_values(array_map(
            fn (string $key): array => [
                'class' => $key,
                'label' => ItemIdentityWarning::from($key)->label(),
                'note' => $this->note(ItemIdentityWarning::from($key), $item),
            ],
            $keys,
        ));
    }

    /**
     * The items tripping ONE warning class, or every item tripping any of
     * them when $class is null. Ordered by name, like the item list itself.
     *
     * Eager-loads what the Resource reads — the Tally group (for
     * `suggested_category`) and the variant base — so a page of rows is a
     * fixed number of queries whatever the page size.
     */
    public function itemsWithWarnings(?string $class = null, int $perPage = self::PER_PAGE_DEFAULT): LengthAwarePaginator
    {
        $sets = $this->sets();

        $ids = $class === null
            ? array_keys($sets['by_item'])
            : ($sets['by_warning'][$class] ?? []);

        return Item::query()
            ->with(['group:id,name', 'variantOf:id,sku,name,display_name'])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Item $item): Item => $this->stamp($item));
    }

    /**
     * Attach the two DERIVED reads to a row the Resource is about to
     * render — the same idiom the item list uses for `can`
     * (ServesConfigurationLifecycle), and for the same reason: a Resource
     * that resolved this service itself would get a FRESH instance per row
     * and re-run the whole sweep for every line of the page.
     *
     * In memory only. Nothing here is a column and nothing saves it — which
     * is safe precisely because there is no write path on this service.
     *
     * STAMP LAST, AND NEVER SAVE A STAMPED ROW. Neither `identity_warnings`
     * nor `identity_suggested_category` is a column, so `save()` on one of
     * these models would try to write two that do not exist and throw. Same
     * hazard, same rule, as the `can` block ServesConfigurationLifecycle
     * stamps — a stamped Item must not be handed to ItemService::update().
     */
    private function stamp(Item $item): Item
    {
        $item->identity_warnings = $this->warningsFor($item);
        $item->identity_suggested_category = $this->suggestedCategoryFor($item);

        return $item;
    }

    /**
     * WHAT TALLY'S GROUPING SUGGESTS THIS ITEM IS — read-only, never
     * persisted by anything, and null wherever the evidence does not reach.
     *
     * @return array{category: ?ItemCategory, confidence: ?string}
     */
    public function suggestedCategoryFor(Item $item): array
    {
        $group = $item->relationLoaded('group') ? $item->group : $item->group()->first();
        $name = $group?->name;

        if ($name === null) {
            return ['category' => null, 'confidence' => null];
        }

        return self::GROUP_SUGGESTIONS[self::foldGroupName($name)] ?? ['category' => null, 'confidence' => null];
    }

    /**
     * THE NAME FOLD, for `possible_duplicate_master` only — lower case, then
     * EVERY character that is not a letter or a digit removed outright.
     * Spaces, hyphens, commas and dots all vanish rather than becoming
     * separators, so '500-ml', '500 ml', '500ml.' and '500ML' are one key.
     *
     * REMOVED RATHER THAN COLLAPSED, and that is the load-bearing choice.
     * Replacing punctuation with a SPACE (which this did until 27-Aug-2026)
     * cannot bridge a MISSING space — '100ml' folds to '100ml' and '100 Ml'
     * to '100 ml', two different keys — and a missing space is precisely
     * this catalogue's known spelling defect: `lib/itemLabel.ts` strips all
     * whitespace for the same reason and names the same pair, and the 26-Aug
     * stock-master XML carries pack-variant names of that shape. A warning
     * that provably cannot fire on the one defect it was built for is not a
     * warning. Widening costs nothing that matters here — this class WARNS,
     * it blocks nothing (Q43 is open), so a fold group a person glances at
     * and dismisses is the whole price.
     *
     * WHAT IT DELIBERATELY DOES NOT DO: reorder words. DEC-20260819-001's own
     * pair — '500ML IFF Tray' -> '500mlifftray' and '500ML Tray IFF' ->
     * '500mltrayiff' — differs by WORD ORDER and is therefore NOT caught.
     * That is a stated limit, not an oversight: token-sorting would collide
     * names that merely share words, and this warning's whole value is that
     * a person can look at the pair and see the answer immediately.
     *
     * IT IS ALSO NOT A SUPERSET OF THE DATABASE'S OWN EQUALITY, which is the
     * limit {@see nameSets()} inherits when it uses this fold to decide which
     * names are worth asking {@see LineMappingResolver} about. MySQL's
     * utf8mb4_unicode_ci folds diacritics and ligatures ('é' = 'e',
     * 'ß' = 'ss') that this keeps distinct, so a pair equal to MySQL and
     * unequal here lands in two fold groups, is never put to the resolver,
     * and would be missed by `outbound_ambiguity`. Stated rather than fixed:
     * on an ASCII bottle catalogue no such pair exists, and the honest fix —
     * asking the resolver about all ~644 distinct names — is 644 queries per
     * request. If a non-ASCII master ever appears, that is the trade to
     * revisit.
     *
     * And it is NOT the comparison the resolver makes. That one matches names
     * exactly as the DATABASE compares them and refuses to be cleverer,
     * because it answers "what will Tally do with this voucher". This one
     * answers "do these two rows look like the same master to a person",
     * which is a different question and is why the two warnings are separate
     * classes.
     */
    public static function foldName(?string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $name))) ?? '';
    }

    /** Group names fold more gently: case and whitespace only, so '&' still separates. */
    public static function foldGroupName(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $name))) ?? '');
    }

    // ---- the sweep -----------------------------------------------------------

    /**
     * @return array{by_item: array<int, list<string>>, by_warning: array<string, list<int>>}
     */
    private function sets(): array
    {
        if ($this->byItem !== null && $this->byWarning !== null) {
            return ['by_item' => $this->byItem, 'by_warning' => $this->byWarning];
        }

        $byWarning = [
            ItemIdentityWarning::MissingTallyMapping->value => $this->missingTallyMapping(),
            ItemIdentityWarning::Unclassified->value => $this->unclassified(),
            ItemIdentityWarning::VariantUomConflict->value => $this->variantUomConflicts(),
            ItemIdentityWarning::FgPurchaseConflict->value => $this->fgPurchaseConflicts(),
            ItemIdentityWarning::InactiveReferenced->value => $this->inactiveReferenced(),
        ];

        // The three name-shaped classes share one pass over the masters.
        $names = $this->nameSets();
        $byWarning[ItemIdentityWarning::DuplicateName->value] = $names['duplicate_name'];
        $byWarning[ItemIdentityWarning::PossibleDuplicateMaster->value] = $names['possible_duplicate_master'];
        $byWarning[ItemIdentityWarning::OutboundAmbiguity->value] = $names['outbound_ambiguity'];

        $byItem = [];
        // Iterated in the ENUM's order, not the array's, so every item's
        // warning list reads in the same order as the health strip.
        foreach (ItemIdentityWarning::cases() as $case) {
            foreach ($byWarning[$case->value] ?? [] as $id) {
                $byItem[$id][] = $case->value;
            }
        }

        $this->byWarning = $byWarning;
        $this->byItem = $byItem;

        return ['by_item' => $byItem, 'by_warning' => $byWarning];
    }

    /**
     * ACTIVE, NOT A FIXTURE, NO TALLY GUID — an item the factory believes is
     * in use that no voucher can ever name.
     *
     * The fixture exclusion is spelled in SQL rather than through
     * {@see Item::isLocalFixture()} on purpose. That method LOGS A WARNING
     * whenever the flag and the SKU prefix disagree, which is right for a
     * posting decision about one item and wrong for a sweep of the whole
     * catalogue: a health strip would write a log line per mismatched row on
     * every page load. The OR semantics are mirrored — EITHER signal marks a
     * fixture — with one knowingly loose edge: `LIKE` folds case on both
     * engines where the model's `str_starts_with` does not, so an SKU spelled
     * 'local-…' is excluded here and would not be a fixture to the model.
     * That direction WITHHOLDS a warning about one oddly-spelled row; the
     * other direction would nag about every rehearsal product forever.
     *
     * @return list<int>
     */
    private function missingTallyMapping(): array
    {
        return Item::query()
            ->where('is_active', true)
            ->whereNull('tally_stock_item_guid')
            ->where('is_local_fixture', false)
            ->where('sku', 'not like', Item::LOCAL_FIXTURE_SKU_PREFIX.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * NOBODY HAS SAID WHAT THIS ITEM IS (Q60).
     *
     * No `is_active` filter, matching the two duplicate classes below: the
     * category is what the document rules read, and a document may name an
     * item that has since been taken out of service. `missing_tally_mapping`
     * is the one class that IS scoped to active, because a voucher can only
     * ever be built from a live master.
     *
     * @return list<int>
     */
    private function unclassified(): array
    {
        return Item::query()
            ->whereNull('category')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The three name classes, from ONE pass over the non-deleted masters
     * plus one resolver question per name that could possibly be shared.
     *
     * ## Who decides what "the same name" means
     *
     * NOT THIS CLASS, for two of the three. `duplicate_name` and
     * `outbound_ambiguity` are whatever {@see LineMappingResolver::item()}
     * calls `ambiguous` — the state a voucher preview shows when a line
     * names one of these — and that verdict rests on SQL equality, collation
     * and all (MySQL's utf8mb4_unicode_ci folds case and trailing spaces;
     * SQLite does not). Re-deriving it in PHP would produce a second
     * definition of "duplicate" that disagrees with posting on one engine or
     * the other, which is the fork the resolver's docblock exists to
     * prevent. So the fold below only narrows WHICH NAMES ARE WORTH ASKING
     * ABOUT — asking about all 644 would be 644 queries, since its memo is
     * per-name — and the resolver answers.
     *
     * THAT NARROWING IS NOT A SUPERSET OF THE DATABASE'S EQUALITY, and the
     * `count($ids) < 2` skip below is where the gap bites: a pair MySQL's
     * collation calls equal but {@see foldName()} keeps apart never reaches
     * the resolver at all. The case, its size and why it is stated rather
     * than closed are in foldName()'s own docblock.
     *
     * `possible_duplicate_master` IS this class's own judgement, because the
     * resolver refuses to make it: it asks "do these two rows look like the
     * same master to a PERSON", which no collation answers. It fires on a
     * fold group holding MORE THAN ONE SPELLING, so a plain exact duplicate
     * — one spelling, several rows — is `duplicate_name` and is not
     * double-badged here. A group holding both an exact duplicate AND a
     * spelling variant legitimately trips both; there really are two things
     * wrong with it.
     *
     * @return array{duplicate_name: list<int>, possible_duplicate_master: list<int>, outbound_ambiguity: list<int>}
     */
    private function nameSets(): array
    {
        $rows = Item::query()->select(['id', 'name'])->get();

        /** @var array<string, list<int>> $byFolded */
        $byFolded = [];
        /** @var array<string, array<string, true>> $spellings */
        $spellings = [];

        foreach ($rows as $row) {
            $exact = (string) $row->name;
            $folded = self::foldName($exact);

            $byFolded[$folded][] = (int) $row->id;
            $spellings[$folded][$exact] = true;
        }

        $duplicate = [];
        $ambiguous = [];
        $possible = [];

        foreach ($byFolded as $folded => $ids) {
            // One row under a fold key cannot be a duplicate of anything by
            // any definition, and cannot be a spelling variant either.
            if (count($ids) < 2) {
                continue;
            }

            foreach (array_keys($spellings[$folded]) as $name) {
                $name = (string) $name;

                if ($this->mappings->item($name)['state'] !== LineMappingResolver::STATE_AMBIGUOUS) {
                    continue;
                }

                // The resolver's OWN row set, so the ids reported are the
                // ones a voucher would be torn between.
                $candidates = $this->mappings->itemCandidates($name);
                $duplicate = [...$duplicate, ...$candidates->map(fn (Item $item): int => (int) $item->id)->all()];

                // "At least one is Tally-linked", read off the rows —
                // never parsed back out of the resolver's note, which its
                // docblock forbids.
                $linked = $candidates->contains(fn (Item $item): bool => $item->tally_stock_item_guid !== null);

                if ($linked) {
                    $ambiguous = [...$ambiguous, ...$candidates->map(fn (Item $item): int => (int) $item->id)->all()];
                }
            }

            if (count($spellings[$folded]) > 1) {
                $possible = [...$possible, ...$ids];
            }
        }

        return [
            'duplicate_name' => array_values(array_unique($duplicate)),
            'possible_duplicate_master' => array_values(array_unique($possible)),
            'outbound_ambiguity' => array_values(array_unique($ambiguous)),
        ];
    }

    /**
     * ONE VARIANT GROUP, MORE THAN ONE UNIT.
     *
     * The group is the base item plus every item pointing at it — one level,
     * because a variant of a variant is refused on the way in. Units are
     * compared as WRITTEN: 'Nos' and 'Nos.' trip this, and that is the
     * intended direction. A spelling difference inside one product's group
     * is worth a person's eye (DEC-20260819-001 turned on exactly this
     * signal — one tray master in 'Kgs.' among seven in 'Nos.'), and a
     * warning that fires once too often costs a glance, while one that folds
     * the spellings together would hide the real case.
     *
     * @return list<int>
     */
    private function variantUomConflicts(): array
    {
        $rows = Item::query()
            ->select(['id', 'uom', 'variant_of_item_id'])
            ->where(function ($query): void {
                $query->whereNotNull('variant_of_item_id')
                    ->orWhereIn('id', Item::query()->select('variant_of_item_id')->whereNotNull('variant_of_item_id'));
            })
            ->get();

        /** @var array<int, list<array{id: int, uom: string}>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $root = (int) ($row->variant_of_item_id ?? $row->id);
            $groups[$root][] = ['id' => (int) $row->id, 'uom' => (string) $row->uom];
        }

        $ids = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $units = array_unique(array_column($members, 'uom'));
            if (count($units) > 1) {
                $ids = [...$ids, ...array_column($members, 'id')];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * CLASSIFIED A FINISHED GOOD, YET BOUGHT.
     *
     * `ItemCategory::purchasable()` already says a finished good may not go
     * on a purchase order — but that rule was written after these lines
     * existed, and it is not retroactive. So either the category is wrong or
     * the factory really does buy this item in; a person decides which, and
     * NOTHING here changes the category or touches the purchase order.
     *
     * TECH DEBT, STATED: this is a scoped query straight at
     * `purchase_order_lines` rather than a call into Procurement, because
     * `PurchaseOrderService` exposes nothing that answers "which items are
     * named on any line". CLAUDE.md wants cross-module reads to go through
     * the other module's Service; the honest fix is a method there, and it
     * is deliberately not being added from an Inventory lane. Read-only,
     * one column, no join — the smallest shape this debt can take.
     *
     * @return list<int>
     */
    private function fgPurchaseConflicts(): array
    {
        return Item::query()
            ->where('category', ItemCategory::FinishedGood->value)
            ->whereIn('id', DB::table('purchase_order_lines')->select('item_id'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * OUT OF SERVICE, YET ON A LIVE ORDER.
     *
     * "Live" is Confirmed or PartiallyDelivered — not a definition chosen
     * here, but the one four services already act on
     * (FulfilmentQueueService, StockReservationService, SalesOrderService,
     * DeliveryService). A Draft order is not yet a promise and a Completed
     * or Cancelled one is not one any more.
     *
     * TECH DEBT, STATED — the same debt {@see fgPurchaseConflicts()} carries
     * and for the same reason. This is a scoped query straight at
     * `sales_order_lines` rather than a call into Sales, because
     * `SalesOrderService` exposes nothing that answers "which items are named
     * on a live order line": its public surface is paginate/cursor/count/
     * show/openCount/openWithLines/create/confirm/cancel, all of which are
     * ORDER-shaped, and openWithLines() caps at ten. CLAUDE.md wants
     * cross-module reads to go through the other module's Service; the honest
     * fix is a method there, and it is deliberately not being added from an
     * Inventory lane. Read-only, one column out, no write path.
     *
     * Neither `sales_order_lines` nor `sales_orders` soft-deletes, so there
     * is no `deleted_at` filter to omit here — checked, not assumed.
     *
     * @return list<int>
     */
    private function inactiveReferenced(): array
    {
        $live = [SalesOrderStatus::Confirmed->value, SalesOrderStatus::PartiallyDelivered->value];

        return Item::query()
            ->where('is_active', false)
            ->whereIn('id', DB::table('sales_order_lines')
                ->select('sales_order_lines.item_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                ->whereIn('sales_orders.status', $live))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    // ---- the sentences -------------------------------------------------------

    /**
     * The one-line explanation a person reads, with this item's own numbers
     * in it. Every open question is NAMED where one governs, so nobody reads
     * a warning as a rule that has been decided.
     */
    private function note(ItemIdentityWarning $warning, Item $item): string
    {
        return match ($warning) {
            ItemIdentityWarning::MissingTallyMapping => 'Active, and carries no Tally stock item GUID — no voucher can '
                .'name it until the masters pull links it (or it is marked a local fixture).',

            ItemIdentityWarning::DuplicateName => $this->sharedNameCount($item).' item masters carry the name "'
                .$item->name.'". Whether that should BLOCK an approval or only warn is Q43, still open — this warns.',

            ItemIdentityWarning::PossibleDuplicateMaster => 'Another master\'s name differs from "'.$item->name
                .'" only by case, spacing or punctuation. DEC-20260819-001 settled one such pair by ARCHIVING the '
                .'redundant master through the lifecycle — reversible, deletes nothing. Nothing is merged here.',

            ItemIdentityWarning::OutboundAmbiguity => 'The name "'.$item->name.'" is shared, and at least one of those '
                .'masters is Tally-linked. A voucher line naming it resolves as ambiguous: Tally would match one by '
                .'name and this ERP cannot say which.',

            ItemIdentityWarning::Unclassified => 'No category recorded. Which ItemCategory each Tally stock group maps '
                .'to is Q60, still open — including the two largest cases, Caps & Closures and Scrap.',

            ItemIdentityWarning::VariantUomConflict => 'This pack-variant group carries more than one unit of measure. '
                .'Variants of one product are counted in one unit (DEC-20260821-001).',

            ItemIdentityWarning::FgPurchaseConflict => 'Classified a finished good, and named on a purchase-order line. '
                .'Either the category or the line is wrong; which categories each document may use is Q59, still open.',

            ItemIdentityWarning::InactiveReferenced => 'Out of service, and named on a confirmed or partially '
                .'delivered sales order.',
        };
    }

    /** How many masters share this item's exact name — counted, never parsed out of a note. */
    private function sharedNameCount(Item $item): int
    {
        return $this->mappings->itemCandidates((string) $item->name)->count();
    }
}
