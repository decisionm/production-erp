<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\WorkCenter;

/**
 * The production-readiness gate: one place that answers "may this product
 * start on this machine, into this warehouse?" and, when the answer is no,
 * names every missing master field in the supervisor's language.
 *
 * Why this exists: before the gate, a product with no cycle time, no
 * cavities, no weight and no Tally identity could be started, run for a
 * whole shift, and only fail at the very end — either as a voucher Tally
 * refuses ("Stock Item does not exist") or as an expected-vs-actual block
 * full of dashes. The cost of a missing master was paid after the work, by
 * the accountant. The gate moves that cost to before the work, onto the
 * screen of the person who can still choose a different product.
 *
 * Severity is configurable per check (config/production.php → readiness):
 *
 *   'block' — Start Batch is refused
 *   'warn'  — shown to the supervisor, start allowed
 *   'off'   — not evaluated
 *
 * Severity is configurable rather than hard-coded for a specific operational
 * reason: readiness depends entirely on how much master data has been loaded,
 * and that differs per deployment and per week. A factory whose consumption
 * recipes are still being built needs recipe warnings; the same factory a
 * month later wants them blocking. Neither is a code change.
 */
class ProductReadinessService
{
    public function __construct(
        private readonly TallyGodownResolver $godowns,
    ) {}

    /**
     * Every check this gate knows how to make, in the order a supervisor
     * would naturally read them: what the product IS, then how it RUNS,
     * then how it is PACKED, then how it reaches Tally.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'item_active' => 'Product is active',
        'uom' => 'Unit of measure',
        'weight' => 'Product weight (grams)',
        'cycle_time' => 'Standard cycle time',
        'cavities' => 'Standard cavities',
        'packing' => 'Packing configuration (pieces per box)',
        'colour' => 'Colour',
        'tally_item' => 'Tally stock item mapping',
        'tally_godown' => 'Tally godown mapping (warehouse)',
        'machine_active' => 'Machine is active',
    ];

    /**
     * The sentence each check speaks when it fails. Extracted from failures()
     * into a constant for one reason: the Product Standards workspace lists
     * the SAME gaps against a product row, and a workspace that paraphrases
     * the gate produces two vocabularies for one fact — the supervisor is
     * told one thing on the configuration page and another when the batch is
     * refused. Both now read the same strings from here.
     *
     * @var array<string, string>
     */
    private const SENTENCES = [
        'weight' => 'No product weight — pieces cannot be converted to kg, so rejection and reconciliation cannot be calculated.',
        'cycle_time' => 'No standard cycle time — expected output and efficiency cannot be calculated.',
        'cavities' => 'No standard cavities — expected output cannot be calculated.',
        'packing' => 'No packing configuration — pieces per box is needed to convert boxes to pieces.',
        'colour' => 'No colour — the masterbatch suggestion and the amber/clear scrap item cannot be resolved.',
        'tally_item' => 'The product does not exist in Tally — any voucher naming it will be rejected.',
    ];

    /**
     * Where a person goes to close each gap. A gap without a destination is
     * the "vague note" the workspace exists to abolish: naming the missing
     * field is only half an instruction if the reader still has to guess
     * which screen owns it.
     *
     * Four destinations for six gaps, because three of the gaps (the run
     * figures) are the same edit.
     *
     * @var array<string, string>
     */
    private const FIX_TARGETS = [
        'weight' => 'standard_edit',
        'cycle_time' => 'standard_edit',
        'cavities' => 'standard_edit',
        'packing' => 'packing_edit',
        'colour' => 'item_colour',
        'tally_item' => 'attach_item',
    ];

    /**
     * The product-configuration gaps on ONE standard row, numbered in the
     * order a supervisor reads them: what the product runs to, how it is
     * packed, what colour it is, and whether Tally has heard of it.
     *
     * Six checks, not ten: the other four in this class need a context a
     * configuration screen does not have. `tally_godown` and `machine_active`
     * are judged against a warehouse and a machine that only an intended RUN
     * names, and `item_active`/`uom` belong to the item master rather than to
     * this product's production configuration.
     *
     * SEVERITY-BLIND on purpose. assess() reads config/production.readiness
     * and, with the master switch off, reports every failure as a warning
     * instead — correct for a gate that is being watched before it is allowed
     * to bite, and wrong here, because it would empty the Incomplete view and
     * declare a factory of half-configured products ready. A gap is a missing
     * master whether or not the gate currently refuses it.
     *
     * @return list<array{number: int, key: string, label: string, sentence: string, fix_target: string}>
     */
    public function configurationGaps(
        ?Item $item,
        ?ProductionStandard $standard = null,
        ?ProductionStandardPackaging $packaging = null,
    ): array {
        $missing = [];

        // The same precedence every consumer uses: the factory standard
        // outranks the item master. A figure present on either side is not a
        // gap, because the run will find it.
        if (! $this->positive($standard?->unit_weight_grams ?? $item?->nominal_weight_grams)) {
            $missing[] = 'weight';
        }
        if (! $this->positive($standard?->cycle_time ?? $item?->standard_cycle_time)) {
            $missing[] = 'cycle_time';
        }
        if (! $this->positive($standard?->cavities ?? $item?->standard_cavities)) {
            $missing[] = 'cavities';
        }
        if (! $this->positive($packaging?->nos_per_box ?? $item?->nos_per_box)) {
            $missing[] = 'packing';
        }
        if (trim((string) $item?->colour) === '') {
            $missing[] = 'colour';
        }

        // A standard with no item at all has no Tally identity by
        // definition, so it fails the same check for the same reason and
        // fix_target sends the reader to the attach screen either way.
        //
        // A LOCAL- fixture is skipped here exactly as it is in failures():
        // it was fabricated BECAUSE Tally does not carry the product, and
        // reporting a deliberate state as a gap on most of the catalogue is
        // how a gap list stops being read. The consequence is not hidden —
        // the workspace's `tally` block still reports guid_present false and
        // says the voucher cannot sync. A gap is something to fix here; that
        // is something to know.
        if ($item === null || ($item->tally_stock_item_guid === null && ! $item->isLocalFixture())) {
            $missing[] = 'tally_item';
        }

        return array_map(fn (int $index, string $key) => [
            // Numbered, not bulleted. "Two things are missing: 1… 2…" is a
            // job a person can work through and report back on; a bullet list
            // is a mood.
            'number' => $index + 1,
            'key' => $key,
            'label' => self::LABELS[$key],
            'sentence' => self::SENTENCES[$key],
            'fix_target' => self::FIX_TARGETS[$key],
        ], array_keys($missing), $missing);
    }

    /**
     * Assess one intended production run. Warehouse and machine are optional
     * so the same method serves both the product picker (product-only, "is
     * this product ever runnable?") and Start Batch (the full context).
     *
     * The resolved factory standard and packaging are optional for the same
     * reason and matter for a sharper one: the four figures they carry
     * (weight, cycle time, cavities, pieces per box) are the ones the run
     * will ACTUALLY use. BatchEstimationService already states the rule —
     * "the factory product standard outranks the item master" — and
     * startBatch() snapshots by the same precedence. Without them here, this
     * gate reads a different source than every other consumer and reports a
     * product's weight missing in the same response that quotes its expected
     * kg. Passing them makes the gate agree with the run it is gating; it
     * invents nothing, because every value it then sees is one already in
     * the database and already destined for the entry.
     *
     * @return array{
     *     ready: bool, blocking: list<array{code: string, label: string, detail: string}>,
     *     warnings: list<array{code: string, label: string, detail: string}>,
     *     summary: ?string, is_local_fixture: bool,
     * }
     */
    public function assess(
        Item $item,
        ?Warehouse $warehouse = null,
        ?WorkCenter $workCenter = null,
        ?ProductionStandard $standard = null,
        ?ProductionStandardPackaging $packaging = null,
        ?object $configuration = null,
    ): array {
        $severities = config('production.readiness.checks', []);
        $failures = $this->failures($item, $warehouse, $workCenter, $standard, $packaging, $configuration);

        $blocking = [];
        $warnings = [];

        foreach ($failures as $code => $detail) {
            $severity = $severities[$code] ?? 'block';
            if ($severity === 'off') {
                continue;
            }

            $entry = ['code' => $code, 'label' => self::LABELS[$code] ?? $code, 'detail' => $detail];
            if ($severity === 'block') {
                $blocking[] = $entry;
            } else {
                $warnings[] = $entry;
            }
        }

        // The master switch turns enforcement off without hiding the
        // findings — everything still reports, nothing refuses. This is how
        // a factory watches the gate for a week before letting it bite.
        if (! config('production.readiness.enforced', true)) {
            $warnings = [...$blocking, ...$warnings];
            $blocking = [];
        }

        return [
            'ready' => $blocking === [],
            'blocking' => $blocking,
            'warnings' => $warnings,
            'summary' => $blocking === [] ? null : $this->summary($blocking),
            // Not a finding — a fact about the item the caller must state
            // plainly ("Local-only fixture — voucher posting disabled")
            // instead of leaving the supervisor to infer it from a check
            // that silently didn't run.
            'is_local_fixture' => $item->isLocalFixture(),
        ];
    }

    /**
     * One sentence a supervisor can act on, naming the missing fields
     * rather than saying "not production-ready" and leaving them guessing.
     */
    private function summary(array $blocking): string
    {
        $labels = array_map(fn ($entry) => strtolower($entry['label']), $blocking);

        if (count($labels) === 1) {
            return "This product is not production-ready: {$labels[0]} is missing.";
        }

        $last = array_pop($labels);

        return 'This product is not production-ready: '.implode(', ', $labels)." and {$last} are missing.";
    }

    /**
     * Raw check results, severity-blind — [check code => why it failed].
     * Passing checks are absent.
     *
     * @return array<string, string>
     */
    private function failures(
        Item $item,
        ?Warehouse $warehouse,
        ?WorkCenter $workCenter,
        ?ProductionStandard $standard = null,
        ?ProductionStandardPackaging $packaging = null,
        ?object $configuration = null,
    ): array {
        $failures = [];

        if (! $item->is_active) {
            $failures['item_active'] = 'The product is deactivated and cannot be produced.';
        }

        if (trim((string) $item->uom) === '') {
            $failures['uom'] = 'No unit of measure on the item master.';
        }

        // The four run figures, each read in the same precedence the run
        // itself will use: factory standard first, item master second. A
        // product whose standard is unpicked (several variants, none chosen)
        // falls back to the item master and may still report these missing —
        // which is honest, because until the supervisor says which variant
        // this run is, nothing knows what it weighs either.
        if (! $this->positive($configuration?->unit_weight_grams ?? $standard?->unit_weight_grams ?? $item->nominal_weight_grams)) {
            $failures['weight'] = self::SENTENCES['weight'];
        }

        if (! $this->positive($configuration?->default_cycle_time ?? $standard?->cycle_time ?? $item->standard_cycle_time)) {
            $failures['cycle_time'] = self::SENTENCES['cycle_time'];
        }

        if (! $this->positive($configuration?->default_cavities ?? $standard?->cavities ?? $item->standard_cavities)) {
            $failures['cavities'] = self::SENTENCES['cavities'];
        }

        if (! $this->positive($packaging?->nos_per_box ?? $item->nos_per_box)) {
            $failures['packing'] = self::SENTENCES['packing'];
        }

        if (trim((string) $item->colour) === '') {
            $failures['colour'] = self::SENTENCES['colour'];
        }

        // A missing consumption recipe is deliberately NOT a finding. The
        // factory decided recipes come after go-live (the masterbatch dosing
        // is an open question with the factory, not a figure to invent), so
        // for now EVERY product lacks one — and a notice that fires on every
        // product on every start is not information, it is wallpaper. The
        // owner read it as an error three times in one night. The expected-
        // materials card simply stays empty until a recipe exists; when
        // recipes become real masters, restore a check here alongside a UI
        // that can act on it.

        // A LOCAL- fixture has no Tally GUID BY CONSTRUCTION — it was
        // fabricated here precisely because Tally does not carry the product
        // yet. Reporting that as a missing master is a false alarm, and a
        // false alarm on 73 of 79 items is how the whole readiness panel
        // stops being read. It is skipped, not downgraded: the caller is
        // handed `is_local_fixture` and states the real consequence
        // ("voucher posting disabled") in its own words. A REAL item missing
        // its GUID is a genuine gap and still fails here.
        if ($item->tally_stock_item_guid === null && ! $item->isLocalFixture()) {
            $failures['tally_item'] = self::SENTENCES['tally_item'];
        }

        // Judged by the SAME TallyGodownResolver the voucher payload and its
        // preview use, so the gate can never refuse a warehouse whose lines
        // would in fact post fine (the internal factory day bin aliasing to
        // its parent godown / the sole company godown), nor pass one whose
        // voucher would die. Only a warehouse NOTHING can stand in for fails.
        if ($warehouse !== null && $this->godowns->resolve($warehouse) === null) {
            $failures['tally_godown'] = "The warehouse \"{$warehouse->name}\" has no Tally godown — any voucher issuing from or receiving into it will be rejected.";
        }

        if ($workCenter !== null && ! $workCenter->is_active) {
            $failures['machine_active'] = "The machine \"{$workCenter->name}\" is deactivated.";
        }

        return $failures;
    }

    private function positive(mixed $value): bool
    {
        return $value !== null && (float) $value > 0;
    }
}
