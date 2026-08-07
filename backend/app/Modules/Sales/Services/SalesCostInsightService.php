<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use App\Modules\Production\Services\ProductionStandardResolver;
use App\Modules\Production\Services\ResinPoolService;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHAT A SALES ORDER ACTUALLY COSTS — honestly, and with the difference
 * between a guess and a fact stated on every figure.
 *
 * ========================= TWO ANSWERS, NEVER MIXED =========================
 *
 * Sales has never had a cost column, so the temptation is to invent one
 * number and put it next to the price. This returns TWO, deliberately kept
 * apart, because they answer different questions and only one of them is a
 * fact:
 *
 *   ESTIMATE — what a piece of this product WOULD cost if it were made from
 *     the material standing in the store today. Always available, always
 *     labelled 'estimate'. Built from the product's own standards (resin
 *     grams per bottle, the masterbatch dose its colour takes, the packing
 *     materials its standard states) priced at what that material costs now.
 *
 *   ACTUAL — what a piece of this product DID cost, taken from the latest
 *     approved batch that carries a live cost-allocation run. Absent
 *     until such a batch exists, and it says so in words rather than
 *     borrowing the estimate and calling it an actual.
 *
 * A line with no batch behind it reports `actual_unit_cost` null and the
 * reason 'no production batch yet — estimate only'. That sentence is the
 * whole point of the endpoint: the salesperson is told what they are looking
 * at, instead of being handed a confident figure with no production behind
 * it.
 *
 * ============== THE RESIN RATE IS THE COMMON POOL'S AVERAGE ==============
 *
 * The estimate prices resin at the weighted average of the COMMON RESIN POOL
 * for that exact material — the same figure a real batch is allocated at
 * (ResinPoolService, via BagCostAllocationService), so the estimate and the
 * actual are two readings of one basis.
 *
 * IT USED TO QUOTE THE NEXT BAG BY FIFO, and that basis died with the rest of
 * the bag-to-batch claim. The owner's correction (2-Aug): the factory has ONE
 * common resin input point serving every machine and a bag is never assigned
 * to a machine or a batch. There is therefore no "next bag this batch will
 * draw from" — every bag goes into the same input, and a single bag's rate
 * would be a price the next bottle does not carry.
 *
 * The pool's rates come through Production's own rate resolver as each bag is
 * loaded, so they are still the audited cost versions — a revised purchase invoice
 * reaches the pool with the next load, and moves no closed batch behind it.
 *
 * When the pool holds nothing priced (nothing loaded yet, or only lots with
 * no recorded rate — opening stock, the commonest case on day one) the
 * estimate falls back to the stock moving average AND SAYS SO in the source
 * words. The fallback is labelled because the two figures mean different
 * things, and a reader deciding whether to quote a price is entitled to know
 * which one they have.
 *
 * ======================= NULL IS A REAL ANSWER =======================
 *
 * The house rule this module inherits from BagCostAllocationService: a total
 * containing an unpriced part is reported as NULL, never as a confident
 * understatement, and a `reason` in words says why. Zero is not a price
 * anywhere in here either — a moving average of 0.0000 means the bin holds no
 * recorded value, so it reads as unknown rather than as free material.
 *
 * There is a THIRD state, and keeping it apart from "unknown" is what makes
 * the endpoint usable at all:
 *
 *   EXCLUDED — a material this product genuinely does not consume (a Clear
 *     bottle takes no masterbatch), or one the factory has deliberately left
 *     open (the tape unit question — see PackingMaterialSuggestionService,
 *     whose `submit_as_stock` flag is OBEYED here rather than re-derived).
 *     An excluded part contributes nothing and does NOT null the total.
 *
 * Nulling every carton-packed product's estimate because the tape unit is
 * still unanswered would null every product in the factory, which is not
 * honesty, it is uselessness. Unknown means "we do not know this price";
 * excluded means "there is no price to know".
 *
 * ========================= READ-ONLY, ABSOLUTELY =========================
 *
 * Nothing here writes. No stock movement, no allocation, no cost version, no
 * touch of stock_balances.average_cost. Sales orders have never touched stock
 * and this endpoint does not start — it is a read over Production's and
 * Inventory's existing services, and a test asserts the world is byte-identical
 * across a call.
 *
 * ===================== WHAT SALES SEES, WHAT FINANCE SEES =====================
 *
 * Costs and margins are for anyone holding sales.view/manage — that is the
 * point of the feature. The ANATOMY behind them — per-kg rates, bag barcodes,
 * supplier lot numbers, which GRN a rate came from — is finance.view/manage
 * only, and the `components` key is ABSENT rather than nulled for everyone
 * else. Same gate and same reasoning as MaterialLotResource and
 * ShiftProductionEntryResource; see MaterialLotResource's docblock for why an
 * invented per-field permission would be stripped by PermissionSeeder.
 *
 * `sources` — the same explanations in words, with no bag or supplier
 * identity in them — is present for everyone, so a salesperson is never shown
 * a figure they cannot account for.
 *
 * ========================== A NOTE ON COUPLING ==========================
 *
 * "Which is the latest approved batch of this product carrying a live
 * allocation run" is asked here against Production's models directly, because
 * Production exposes no service method for it and this wave's scope does not
 * extend to editing that module. It is a READ, expressed in Production's own
 * enums and its own `live()` scope. When Production is next opened it belongs
 * there, beside BagCostAllocationService::summary() which prices the batch it
 * finds.
 */
class SalesCostInsightService
{
    /**
     * Decimal places on a PER-PIECE cost.
     *
     * Six, not the four this codebase prices money at, and the reason is the
     * same one PackingMaterialSuggestionService::CONVERSION_SCALE states in
     * the other direction: a per-bottle figure is a DIVIDED one, three orders
     * of magnitude below the per-kg and per-box rates it comes from. The
     * factory's real masterbatch dose is 0.25 g per bottle — at ~₹300/kg that
     * is ₹0.075, and a carton at ₹40 for 200 bottles is ₹0.20, but a tray
     * component or a lighter dose lands at ₹0.0004 and FOUR places would
     * print it as 0.0000. A cost of zero is exactly the lie this module
     * refuses to tell everywhere else.
     *
     * Components are each computed AT this scale and summed at it, so the
     * parts an auditor can see add up to the total they are shown, exactly.
     */
    public const PER_UNIT_SCALE = 6;

    /** Money, at the scale the rest of the system prices it. */
    public const MONEY_SCALE = 4;

    public const MARGIN_SCALE = 2;

    /**
     * The label the order-level actual block ALWAYS carries.
     *
     * There is no finished-goods cost allocation in this system — no landed
     * cost per delivered piece, no order-level absorption — so an order's
     * "actual" is the batch figures of the products on it, added up. Saying
     * that on the block rather than in a footnote is the difference between a
     * number an accountant can use and one they will later discover was not
     * what they thought.
     */
    public const ORDER_ACTUAL_BASIS = 'from batch actuals, not order-allocated';

    /**
     * PAST THE ACCOUNTANT — the statuses that mean a batch's figures have
     * been approved and may be quoted as fact.
     *
     * Synced is included and that is not an oversight: ShiftProductionEntryStatus
     * documents the accountant as the FINAL gate, with `synced` strictly
     * downstream of `approved`. A batch that has reached Tally is more
     * approved, not less, and filtering on `approved` alone would make every
     * actual disappear the moment its batch synced. `accountant_approved` is
     * deliberately absent — the enum records it as a value that is never
     * written, left behind by a removed fourth gate.
     *
     * @var list<ShiftProductionEntryStatus>
     */
    private const PAST_THE_ACCOUNTANT = [
        ShiftProductionEntryStatus::Approved,
        ShiftProductionEntryStatus::Synced,
    ];

    public function __construct(
        private readonly ProductionStandardResolver $standards,
        private readonly RunMaterialSuggestionService $suggestions,
        private readonly PackingMaterialSuggestionService $packing,
        // The common resin pool, read-only: its weighted average IS the
        // resin estimate. TraceabilityService::pickList and BagLotRateResolver
        // used to be injected here for the retired next-bag-by-FIFO basis and
        // are deliberately gone — leaving them would leave the seam the dead
        // claim could grow back through.
        private readonly ResinPoolService $pool,
        private readonly StockMovementService $stock,
        private readonly FactoryWarehouseResolver $warehouses,
        private readonly BagCostAllocationService $allocations,
    ) {}

    /**
     * The cost picture for one sales order.
     *
     * $withIdentity gates the per-component anatomy — rates, bag barcodes,
     * supplier lot numbers. Totals, margins and the identity-free `sources`
     * words are for everyone with sales access; the anatomy is finance's.
     *
     * @return array<string, mixed>
     */
    public function forOrder(SalesOrder $order, bool $withIdentity = false): array
    {
        $asOf = now()->toIso8601String();

        // withTrashed for the same reason materialCost() uses it: a master
        // cleanup that soft-deletes a product must not blank its name on an
        // order that already sold it.
        $order->loadMissing(['lines.item' => fn ($query) => $query->withTrashed()]);

        // Each distinct product is resolved ONCE. An order commonly repeats a
        // product across lines (different delivery dates, different prices),
        // and every estimate costs a pool read plus several average-cost
        // lookups — paying for them per LINE would multiply the busiest part
        // of this read by nothing gained.
        $estimates = [];
        $actuals = [];

        $lines = [];

        foreach ($order->lines as $line) {
            $itemId = (int) $line->item_id;

            if (! array_key_exists($itemId, $estimates)) {
                $estimates[$itemId] = $this->estimateFor($line->item, $asOf, $withIdentity);
                $actuals[$itemId] = $this->actualFor($itemId, $asOf);
            }

            $lines[] = [
                'sales_order_line_id' => (int) $line->id,
                'item' => $line->item === null ? null : [
                    'id' => (int) $line->item->id,
                    'name' => (string) $line->item->name,
                    'sku' => $line->item->sku,
                ],
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'estimate' => $this->withMargin(
                    $estimates[$itemId],
                    'estimated_unit_cost',
                    'estimated_margin_pct',
                    $line->unit_price,
                ),
                'actual' => $this->withMargin(
                    $actuals[$itemId],
                    'actual_unit_cost',
                    'actual_margin_pct',
                    $line->unit_price,
                ),
            ];
        }

        return [
            'sales_order_id' => (int) $order->id,
            'status' => $order->status->value,
            'as_of' => $asOf,
            'lines' => $lines,
            'order_estimate' => $this->orderTotal($lines, 'estimate', 'estimated_unit_cost', $asOf),
            'order_actual' => $this->orderTotal($lines, 'actual', 'actual_unit_cost', $asOf),
        ];
    }

    // ------------------------------------------------------------- estimate --

    /**
     * What a piece of this product would cost, made from what is in the store
     * today.
     *
     * @return array<string, mixed>
     */
    private function estimateFor(?Item $product, string $asOf, bool $withIdentity): array
    {
        $base = [
            'label' => 'estimate',
            'as_of' => $asOf,
            'estimated_unit_cost' => null,
            'reason' => null,
            'sources' => [],
        ];

        if ($product === null) {
            return [
                ...$base,
                'reason' => 'this order line names a product that is no longer in the item master, so nothing can be costed for it',
            ];
        }

        // Null when the product has several standard variants and none was
        // chosen — resolve()'s own rule. The resin figure survives that (it
        // falls through to the item master's nominal weight); the packing
        // materials do not, and say so.
        $standard = $this->standards->resolve((int) $product->id);

        $components = [
            $this->resinComponent($product, $standard, $asOf),
            $this->masterbatchComponent($product, $asOf),
            ...$this->packagingComponents($product, $standard, $asOf),
        ];

        $total = $this->zero(self::PER_UNIT_SCALE);
        $unknown = [];

        foreach ($components as $component) {
            if ($component['status'] === 'excluded') {
                continue;
            }

            if ($component['per_unit_cost'] === null) {
                $unknown[] = $component['kind'];

                continue;
            }

            $total = bcadd($total, $component['per_unit_cost'], self::PER_UNIT_SCALE);
        }

        $reason = null;

        if ($unknown !== []) {
            $total = null;
            $reason = 'no price is recorded for '.$this->inWords($unknown)
                .', so a unit cost for this product cannot be worked out in full';
        }

        $estimate = [
            ...$base,
            'estimated_unit_cost' => $total,
            'reason' => $reason,
            'sources' => $this->sourceWords($components),
        ];

        // THE ANATOMY IS FINANCE'S — absent, not nulled, for everyone else.
        if ($withIdentity) {
            $estimate['components'] = $components;
        }

        return $estimate;
    }

    /**
     * Resin: the product's own unit weight IS its resin per bottle (a bottle
     * weighing 5 g is 5 g of polymer — RunMaterialSuggestionService's rule,
     * borrowed rather than restated), priced at the common resin pool's
     * weighted average.
     *
     * @return array<string, mixed>
     */
    private function resinComponent(Item $product, ?ProductionStandard $standard, string $asOf): array
    {
        $grams = $this->suggestions->unitWeightGramsFor($product, null, $standard);
        $block = $this->suggestions->resinFor($product, $grams);
        $resinItemId = isset($block['item']['id']) ? (int) $block['item']['id'] : null;

        if ($resinItemId === null) {
            // The suggestion service's reason strings are carefully written
            // and the factory already reads them on the floor — passed
            // through verbatim rather than re-worded into a second vocabulary
            // for the same fact.
            return $this->component('resin', 'unknown', null, 'no resin material could be worked out for this product', (string) $block['reason'], $asOf);
        }

        if ($grams === null) {
            return $this->component(
                'resin',
                'unknown',
                null,
                'no unit weight is recorded for this product',
                'Neither a production standard nor the item master states what one piece weighs, so its resin per piece is unknown.',
                $asOf,
                item: $block['item'],
            );
        }

        [$rate, $rateSource, $words, $identity] = $this->resinRate($resinItemId);

        if ($rate === null) {
            return $this->component(
                'resin',
                'unknown',
                null,
                $words,
                'No purchase rate and no stock average is recorded for this resin, so it cannot be priced.',
                $asOf,
                item: $block['item'],
            );
        }

        return $this->component(
            'resin',
            'priced',
            $this->perPiece($this->kg($grams), $rate),
            $words,
            null,
            $asOf,
            item: $block['item'],
            rate: $rate,
            rateUnit: 'per_kg',
            rateSource: $rateSource,
            basis: "{$grams} g per piece",
            identity: $identity,
        );
    }

    /**
     * THE COMMON RESIN POOL'S WEIGHTED AVERAGE, or the stock moving average
     * when the pool holds nothing priced — labelled either way.
     *
     * This replaced "the rate the next bag out of the store carries". The
     * owner's correction (2-Aug) ended the pick-list-head basis along with
     * the rest of the bag-to-batch claim: with ONE common resin input point
     * serving every machine, there is no "next bag this batch will draw
     * from" — every bag goes into the same input and every batch draws from
     * the same pool. Quoting one bag's rate would be quoting a price the next
     * bottle will not carry.
     *
     * The pool is the same figure a real batch is allocated at
     * (ResinPoolService, via BagCostAllocationService), so the estimate and
     * the actual are two readings of one basis rather than two different
     * theories. NO BAG OR LOT IDENTITY comes back from here any more — the
     * identity array is empty, because the pool has no single bag behind it
     * and naming one would be the dead claim wearing a different hat.
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: array<string, mixed>}
     */
    private function resinRate(int $resinItemId): array
    {
        $poolRate = $this->pool->currentAverage($resinItemId);

        if ($poolRate !== null) {
            return [
                $poolRate,
                'resin_pool_weighted_average',
                'the common resin pool\'s weighted average — an accounting allocation across every bag loaded, not one bag\'s own price',
                [],
            ];
        }

        $average = $this->averageCost($resinItemId);

        return [
            $average,
            $average === null ? null : 'store_average',
            'the store moving average — no priced resin stands in the common pool to price from',
            [],
        ];
    }

    /**
     * Masterbatch: the dose this product's colour takes, at what that
     * colourant currently averages.
     *
     * A Clear bottle takes none, and a product with no colour stated takes
     * none that anyone has recorded — both are EXCLUDED (nothing to price),
     * not unknown. A colour that resolves to no colourant, or to two, IS
     * unknown: the product doses something and the system cannot say what.
     *
     * @return array<string, mixed>
     */
    private function masterbatchComponent(Item $product, string $asOf): array
    {
        $colour = $this->suggestions->colourFor($product);
        $block = $this->suggestions->masterbatchFor($product, $colour);
        $itemId = isset($block['item']['id']) ? (int) $block['item']['id'] : null;

        if ($itemId === null) {
            $excluded = in_array($block['source'], ['no_colour', 'clear'], true);

            return $this->component(
                'masterbatch',
                $excluded ? 'excluded' : 'unknown',
                null,
                $excluded
                    ? 'no masterbatch — this product is not recorded as dosing one'
                    : 'the masterbatch this product doses could not be worked out',
                (string) $block['reason'],
                $asOf,
            );
        }

        $grams = $block['grams_per_bottle'];

        if ($grams === null) {
            return $this->component(
                'masterbatch',
                'unknown',
                null,
                'no dosing figure is recorded for this masterbatch',
                (string) $block['reason'],
                $asOf,
                item: $block['item'],
            );
        }

        $average = $this->averageCost($itemId);

        if ($average === null) {
            return $this->component(
                'masterbatch',
                'unknown',
                null,
                'no stock average is recorded for this masterbatch',
                'The bin holds no recorded value for this colourant, so its dose cannot be priced.',
                $asOf,
                item: $block['item'],
            );
        }

        return $this->component(
            'masterbatch',
            'priced',
            $this->perPiece($this->kg($grams), $average),
            'the dosing standard for this colour, at the store moving average',
            null,
            $asOf,
            item: $block['item'],
            rate: $average,
            rateUnit: 'per_kg',
            rateSource: 'store_average',
            basis: "{$grams} g per piece",
        );
    }

    /**
     * The packing materials this product's standard states, each divided down
     * to one piece.
     *
     * Carton and film are dosed PER CARTON and tray per tray — PackingMaterialMapping's
     * KIND_BASIS, which is a property of the kind and never read off the
     * item's unit. So the per-piece figure divides by how many pieces go in
     * that container.
     *
     * @return list<array<string, mixed>>
     */
    private function packagingComponents(Item $product, ?ProductionStandard $standard, string $asOf): array
    {
        if ($standard === null) {
            return [$this->component(
                'packaging',
                'unknown',
                null,
                'no production standard applies to this product',
                'Without a standard the app cannot say what this product is packed in, so its packing cost is unknown.',
                $asOf,
            )];
        }

        $entries = $this->packing->forStandard($standard);

        if ($entries === []) {
            // Not a missing mapping — a product the workbook says nothing
            // about, which PackingMaterialSuggestionService is explicit is a
            // different thing. Nothing to price rather than a price unknown.
            return [$this->component(
                'packaging',
                'excluded',
                null,
                'this product\'s standard states no packing materials',
                'The production standard names no carton, tray or pouch for this product, so none is costed.',
                $asOf,
            )];
        }

        $packaging = $this->standards->resolvePackaging($standard);

        // A STANDING LINE NOBODY HAS NAMED YET IS NOT AN UNKNOWN COST.
        //
        // Every suggested line is a material the workbook says the product
        // consumes, so all of them price (or honestly withhold the estimate).
        // The standing final-carton/polymer-cover exclusion that used to sit
        // here died with the lines themselves (DEC-20260807-015).
        return array_map(
            fn (array $entry) => $this->packagingComponent($entry, $product, $packaging, $asOf),
            $entries,
        );
    }

    /**
     * One packing material, divided down to a piece.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function packagingComponent(
        array $entry,
        Item $product,
        ?ProductionStandardPackaging $packaging,
        string $asOf,
    ): array {
        $kind = (string) $entry['kind'];

        // THE TAPE GATE, OBEYED NOT RE-DERIVED. submit_as_stock is false only
        // while the tape's metres and its Tally unit disagree about what is
        // being counted — the factory's open question. A length priced
        // against a rate per No would be a confident wrong number, so the
        // line is carried, named, and left out of the money.
        if ($entry['submit_as_stock'] !== true) {
            return $this->component(
                $kind,
                'excluded',
                null,
                'left out of the cost — its unit is still an open question in the factory',
                (string) $entry['reason'],
                $asOf,
                item: $entry['item'],
            );
        }

        $itemId = isset($entry['item']['id']) ? (int) $entry['item']['id'] : null;

        if ($itemId === null) {
            return $this->component(
                $kind,
                'unknown',
                null,
                'no Tally item is mapped to this packing spec',
                (string) $entry['reason'],
                $asOf,
            );
        }

        $factor = $entry['factor'];

        if ($factor === null) {
            return $this->component(
                $kind,
                'unknown',
                null,
                'no dose is recorded for this packing material',
                (string) $entry['reason'],
                $asOf,
                item: $entry['item'],
            );
        }

        [$per, $perWords] = $this->piecesPerContainer($kind, $packaging, $product);

        if ($per === null) {
            return $this->component(
                $kind,
                'unknown',
                null,
                'nobody has recorded how many pieces go in one '.$this->containerWord($kind),
                'Neither the standard\'s packaging option nor the item master states the count, so this material cannot be divided down to a piece.',
                $asOf,
                item: $entry['item'],
            );
        }

        $average = $this->averageCost($itemId);

        if ($average === null) {
            return $this->component(
                $kind,
                'unknown',
                null,
                'no stock average is recorded for this packing material',
                'The store holds no recorded value for this material, so it cannot be priced.',
                $asOf,
                item: $entry['item'],
            );
        }

        // Film's factor is stated in GRAMS per carton while its stock moves in
        // kilograms — the ÷1000 that PackingMaterialMapping::KIND_BASIS keeps
        // visible as factor_unit vs unit, done here rather than buried in a
        // reason string.
        $quantityPerContainer = $kind === PackingMaterialMapping::KIND_POUCH_FILM
            ? $this->kg((string) $factor)
            : bcadd((string) $factor, '0', 8);

        return $this->component(
            $kind,
            'priced',
            bcdiv(bcmul($quantityPerContainer, $average, 8), $per, self::PER_UNIT_SCALE),
            'the standard\'s packing mapping, at the store moving average',
            null,
            $asOf,
            item: $entry['item'],
            rate: $average,
            rateUnit: 'per_'.((string) $entry['unit']),
            rateSource: 'store_average',
            basis: "{$entry['factor']} {$entry['factor_unit']} per {$entry['quantity_basis']}, {$perWords}",
        );
    }

    /**
     * How many pieces one container of this kind holds, and where that count
     * came from.
     *
     * Standard packaging option first, item master second — the same
     * precedence the rest of the module applies to a resolved figure, so the
     * estimate quotes the count the floor is actually packing to.
     *
     * @return array{0: ?string, 1: string}
     */
    private function piecesPerContainer(string $kind, ?ProductionStandardPackaging $packaging, Item $product): array
    {
        [$fromPackaging, $fromItem] = $kind === PackingMaterialMapping::KIND_TRAY
            ? [$packaging?->nos_per_tray, $product->nos_per_tray]
            : [$packaging?->nos_per_box, $product->nos_per_box];

        $word = $this->containerWord($kind);

        foreach ([[$fromPackaging, 'standard'], [$fromItem, 'item master']] as [$value, $where]) {
            if ($value !== null && bccomp((string) $value, '0', 4) === 1) {
                return [bcadd((string) $value, '0', 4), "{$value} pieces per {$word} (from the {$where})"];
            }
        }

        return [null, "pieces per {$word} not recorded"];
    }

    private function containerWord(string $kind): string
    {
        return $kind === PackingMaterialMapping::KIND_TRAY ? 'tray' : 'carton';
    }

    // --------------------------------------------------------------- actual --

    /**
     * What a piece of this product DID cost, from the latest approved batch
     * carrying a live allocation run — see approvedBatches() for what
     * "latest" and "approved" mean here.
     *
     * @return array<string, mixed>
     */
    private function actualFor(int $itemId, string $asOf): array
    {
        $base = [
            'label' => 'actual',
            'as_of' => $asOf,
            'actual_unit_cost' => null,
            'reason' => null,
            'source' => null,
        ];

        $entry = $this->approvedBatches($itemId)
            // A live run is what makes a batch costable at all: a reversed run
            // belongs to a completion that was amended away, and the batch's
            // real figures are on the run that replaced it.
            ->whereIn('id', BatchResinAllocation::query()->live()->select('shift_production_entry_id'))
            ->first();

        if ($entry === null) {
            // TWO SILENCES, AND THEY ARE NOT THE SAME SILENCE. "Nothing has
            // been made yet" and "what was made has been amended and not yet
            // re-costed" send a reader to completely different places, and
            // telling an accountant there is no batch for one they approved
            // yesterday is precisely the confidently-wrong sentence this
            // module refuses to write everywhere else. The second query runs
            // only on the miss path, where there is nothing else to pay for.
            return [
                ...$base,
                'reason' => $this->approvedBatches($itemId)->exists()
                    ? 'this product\'s latest approved batch has had its costing withdrawn by a correction and not yet re-costed, so there is no actual to quote — estimate only'
                    : 'no production batch yet — estimate only',
            ];
        }

        $summary = $this->allocations->summary($entry);
        $perUnit = $summary['cost_per_accepted_unit'];

        return [
            ...$base,
            'actual_unit_cost' => $perUnit,
            'reason' => $perUnit === null ? $summary['reason'] : null,
            'source' => [
                'shift_production_entry_id' => (int) $entry->id,
                'batch_number' => $entry->batch_number,
                // There is no completion timestamp column on this table. The
                // date the batch RAN and the moment the accountant approved it
                // are what exist, so those are what is reported — named for
                // what they are rather than dressed up as a completed_at that
                // the schema does not have.
                'production_date' => $entry->production_date?->toDateString(),
                'approved_at' => $entry->approved_at?->toIso8601String(),
                'basis' => 'the latest approved batch of this product, at the common resin pool\'s weighted average — an accounting allocation, not physical bag traceability',
            ],
        ];
    }

    /**
     * This product's completed batches that are past the accountant, newest
     * shift first.
     *
     * Ordered by production date then id — the shift the material was
     * actually burnt on, not the order rows were written in, so a batch
     * entered late for an earlier day cannot present itself as the newest
     * fact about this product.
     *
     * @return Builder<ShiftProductionEntry>
     */
    private function approvedBatches(int $itemId)
    {
        return ShiftProductionEntry::query()
            ->where('item_id', $itemId)
            ->where('batch_status', BatchStatus::Completed)
            ->whereIn('status', array_map(fn (ShiftProductionEntryStatus $s) => $s->value, self::PAST_THE_ACCOUNTANT))
            ->orderByDesc('production_date')
            ->orderByDesc('id');
    }

    // ---------------------------------------------------------------- money --

    /**
     * Add the margin against a line's price to a cost block.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function withMargin(array $block, string $costKey, string $marginKey, mixed $unitPrice): array
    {
        $cost = $block[$costKey];
        $block[$marginKey] = null;

        if ($cost === null) {
            return $block;
        }

        $price = bcadd((string) $unitPrice, '0', self::MONEY_SCALE);

        // A zero price has no margin to state — dividing by it would be a
        // fatal, and calling it -100% would assert a giveaway nobody recorded.
        //
        // ??= AND NOT =, deliberately: a block that already carries a reason
        // is explaining why its COST could not be worked out, which is the
        // more fundamental fact and the one a reader must act on first.
        // Overwriting it with the price note would hide a missing rate behind
        // a missing price.
        if (bccomp($price, '0', self::MONEY_SCALE) !== 1) {
            $block['reason'] ??= 'this line has no unit price, so there is nothing to measure a margin against';

            return $block;
        }

        $block[$marginKey] = bcdiv(bcmul(bcsub($price, $cost, 8), '100', 8), $price, self::MARGIN_SCALE);

        return $block;
    }

    /**
     * The order-level roll-up for one label.
     *
     * NEVER A PARTIAL TOTAL, and for the actual block that is the strict rule
     * the owner asked for: unless EVERY line has an actual, there is no
     * order-level actual at all. An order two-thirds of which has been made
     * has no honest actual margin, and averaging the third that has with the
     * estimate of the rest would produce precisely the confident-looking
     * hybrid this endpoint exists to refuse.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function orderTotal(array $lines, string $label, string $costKey, string $asOf): array
    {
        $block = [
            'label' => $label,
            'as_of' => $asOf,
            'cost_total' => null,
            'revenue_total' => null,
            'margin_pct' => null,
            'reason' => null,
        ];

        // The order-level actual ALWAYS says what it is, present or absent —
        // there is no finished-goods allocation in this system and a reader
        // must never infer one from a number that happens to be there.
        if ($label === 'actual') {
            $block['basis'] = self::ORDER_ACTUAL_BASIS;
        }

        if ($lines === []) {
            return [...$block, 'reason' => 'this order has no lines to cost'];
        }

        $cost = $this->zero(self::MONEY_SCALE);
        $revenue = $this->zero(self::MONEY_SCALE);
        $missing = 0;

        foreach ($lines as $line) {
            $quantity = bcadd((string) $line['quantity'], '0', self::MONEY_SCALE);
            $revenue = bcadd($revenue, bcmul($quantity, (string) $line['unit_price'], self::MONEY_SCALE), self::MONEY_SCALE);

            $unit = $line[$label][$costKey];

            if ($unit === null) {
                $missing++;

                continue;
            }

            $cost = bcadd($cost, bcmul($quantity, $unit, self::MONEY_SCALE), self::MONEY_SCALE);
        }

        $block['revenue_total'] = $revenue;

        if ($missing > 0) {
            return [
                ...$block,
                'reason' => $label === 'actual'
                    ? "{$missing} of this order's lines have no approved batch behind them, so this order has no actual cost — only line-by-line estimates"
                    : "{$missing} of this order's lines could not be costed in full, so the order total would understate itself",
            ];
        }

        $block['cost_total'] = $cost;

        if (bccomp($revenue, '0', self::MONEY_SCALE) === 1) {
            $block['margin_pct'] = bcdiv(bcmul(bcsub($revenue, $cost, 8), '100', 8), $revenue, self::MARGIN_SCALE);
        } else {
            $block['reason'] = 'this order carries no value, so there is nothing to measure a margin against';
        }

        return $block;
    }

    // --------------------------------------------------------------- shaping --

    /**
     * One costed (or uncosted) part of a piece.
     *
     * @param  array{id: int, name: string}|null  $item
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function component(
        string $kind,
        string $status,
        ?string $perUnitCost,
        string $source,
        ?string $reason,
        string $asOf,
        ?array $item = null,
        ?string $rate = null,
        ?string $rateUnit = null,
        ?string $rateSource = null,
        ?string $basis = null,
        array $identity = [],
    ): array {
        return [
            'kind' => $kind,
            'status' => $status,
            'per_unit_cost' => $perUnitCost,
            'source' => $source,
            'reason' => $reason,
            'as_of' => $asOf,
            'item' => $item,
            'rate' => $rate,
            'rate_unit' => $rateUnit,
            'rate_source' => $rateSource,
            'basis' => $basis,
            ...$identity,
        ];
    }

    /**
     * The identity-free explanation of every part, for readers who may see
     * the money but not the rates behind it.
     *
     * @param  list<array<string, mixed>>  $components
     * @return array<string, string>
     */
    private function sourceWords(array $components): array
    {
        $words = [];

        foreach ($components as $component) {
            $words[(string) $component['kind']] = (string) $component['source'];
        }

        return $words;
    }

    /** Grams as kilograms, at the scale the multiplication needs. */
    private function kg(string $grams): string
    {
        return bcdiv($grams, '1000', 8);
    }

    /** One piece's worth of a material, at a rate per its own unit. */
    private function perPiece(string $quantity, string $rate): string
    {
        return bcmul($quantity, $rate, self::PER_UNIT_SCALE);
    }

    /**
     * A stock moving average, or null when there is none.
     *
     * ZERO IS NOT A PRICE — currentAverageCost answers '0.0000' for a
     * combination that has never had a balance row, and for a bin that holds
     * no recorded value. Both mean "unknown", and this module never prints a
     * material as free. Same rule as BagLotRateResolver and materialCost().
     *
     * The warehouse is Production's own item-aware answer, so the estimate
     * prices material where that material actually sits: kg raw material in
     * the day bin, packing material in the store it never leaves.
     */
    private function averageCost(int $itemId): ?string
    {
        $warehouse = $this->warehouses->consumptionSource($itemId);

        if ($warehouse === null) {
            return null;
        }

        $average = $this->stock->currentAverageCost($itemId, (int) $warehouse->id);

        return is_numeric($average) && bccomp((string) $average, '0', self::MONEY_SCALE) === 1
            ? bcadd((string) $average, '0', self::MONEY_SCALE)
            : null;
    }

    private function zero(int $scale): string
    {
        return bcadd('0', '0', $scale);
    }

    /**
     * A list of material names as a person would say it.
     *
     * @param  list<string>  $kinds
     */
    private function inWords(array $kinds): string
    {
        $kinds = array_values(array_unique($kinds));

        if (count($kinds) === 1) {
            return $kinds[0];
        }

        $last = array_pop($kinds);

        return implode(', ', $kinds)." and {$last}";
    }
}
