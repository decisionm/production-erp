<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Data\MouldItemMap;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One mould standard, several Tally items.
 *
 * The factory's rule: a mould standard covers EVERY colour variant of that
 * bottle. Tally sells the colours as separate SKUs, so a single set of figures
 * has to reach several items — and each item must be able to find those figures
 * on its own, because the Start Batch screen resolves standards by item.
 *
 * The item names below are verbatim from the factory's real Tally catalogue
 * (their All Masters export). They are the point of the test: the whole reason
 * matching was hard is that these names look nothing like the workbook's
 * "15ML ROUND", and a test using tidy invented names would prove nothing.
 */
class MouldItemFanOutTest extends TestCase
{
    use RefreshDatabase;

    /** Real Tally names, so the map is exercised against what it was written for. */
    private const REAL_ITEMS = [
        'A.15ml Round Pet Bottle Amber-5gms',
        'A.15ml Round Pet Bottle Clear -5gms',
        'B.170 Ml Round Pet Bottle Amber-16.5gms',
        'B.170 Ml Round Pet Bottle Clear-16.5gms',
        'B.170ml Round Milk White-16.5gms',
        'B.250ml Square Pet Bottle Clear-18gms',
        // Present but must never be claimed: 500ML ROUND is deliberately
        // unmapped because its cycle time is contradicted by the factory's own
        // daily production reports.
        'B.500 Ml Round Pet Bottle Amber - 36gms',
        'B.500 Ml Round Pet Bottle Clear - 31.5gm',
    ];

    private function seedCatalogue(): void
    {
        foreach (self::REAL_ITEMS as $i => $name) {
            Item::create([
                'sku' => $name,          // Tally has no separate short code for these
                'name' => $name,
                'uom' => 'NOS',
                'is_active' => true,
                'tally_stock_item_guid' => 'guid-fanout-'.$i,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/product-master-rows.json')), true);
    }

    private function import(): array
    {
        return app(ProductionStandardImportService::class)->import($this->rows(), false, null);
    }

    public function test_one_mould_standard_reaches_every_colour_of_its_bottle(): void
    {
        $this->seedCatalogue();
        $this->import();

        $rows = ProductionStandard::where('source_product_name', '15ML ROUND')
            ->whereNotNull('item_id')->with('item')->get();

        // Two SKUs, two rows — not one row against whichever colour happened to
        // be written last, which is what a variant-only key produced.
        $this->assertCount(2, $rows);
        $this->assertSame([
            'A.15ml Round Pet Bottle Amber-5gms',
            'A.15ml Round Pet Bottle Clear -5gms',
        ], $rows->pluck('item.name')->sort()->values()->all());

        // Identical figures on both: it is ONE standard, recorded twice.
        $this->assertSame([5], $rows->pluck('cavities')->unique()->values()->all());
        $this->assertSame(1, $rows->pluck('cycle_time')->unique()->count());
        $this->assertSame(1, $rows->pluck('unit_weight_grams')->unique()->count());
        $this->assertSame('10.80', (string) $rows->first()->cycle_time);
        $this->assertSame('5.0000', (string) $rows->first()->unit_weight_grams);
    }

    public function test_a_three_colour_bottle_produces_three_rows(): void
    {
        $this->seedCatalogue();
        $this->import();

        $rows = ProductionStandard::where('source_product_name', '170ml round')
            ->whereNotNull('item_id')->with('item')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['B.170 Ml Round Pet Bottle Amber-16.5gms', 'B.170 Ml Round Pet Bottle Clear-16.5gms', 'B.170ml Round Milk White-16.5gms'],
            $rows->pluck('item.name')->sort()->values()->all(),
        );
    }

    public function test_each_item_resolves_exactly_one_standard(): void
    {
        // The property the Start Batch picker depends on. variantsFor() filters
        // on item_id alone, so two standards on one item would show a supervisor
        // two indistinguishable choices carrying different cycle times.
        $this->seedCatalogue();
        $this->import();

        foreach (Item::all() as $item) {
            $count = ProductionStandard::where('item_id', $item->id)->count();
            $this->assertLessThanOrEqual(
                1,
                $count,
                "Item \"{$item->name}\" resolved {$count} standards — the variant picker cannot tell them apart.",
            );
        }
    }

    public function test_the_disputed_500ml_round_claims_nothing(): void
    {
        // Its sheet cell pairs weight "36 / 31.5" with cycle time "21.5 / 17.8",
        // and the factory's daily reports log every 36 g run at 18.16-19.0 s,
        // never 21.5 — that is 500 ml JLI's rate. Both 500 ml Round items are
        // seeded, so this asserts a deliberate refusal, not an absent fixture.
        $this->seedCatalogue();
        $this->import();

        foreach (['B.500 Ml Round Pet Bottle Amber - 36gms', 'B.500 Ml Round Pet Bottle Clear - 31.5gm'] as $name) {
            $item = Item::where('name', $name)->firstOrFail();
            $this->assertSame(0, ProductionStandard::where('item_id', $item->id)->count(), $name.' must stay unconfigured.');
        }
    }

    public function test_re_importing_creates_no_extra_rows(): void
    {
        $this->seedCatalogue();
        $this->import();
        $first = ProductionStandard::count();

        $this->import();

        // Idempotency is what makes a corrected sheet safe to re-import. The
        // row key had to gain item_id for this to hold under fan-out.
        $this->assertSame($first, ProductionStandard::count());
    }

    public function test_every_mapped_item_name_is_unique_across_moulds(): void
    {
        // The safety property behind the fan-out: if two mould names claimed the
        // same SKU, that item would carry two standards with different figures
        // and nothing could choose between them. Asserted on the map itself so a
        // future edit cannot introduce it.
        $seen = [];
        foreach (MouldItemMap::ENTRIES as $entry) {
            foreach ($entry['items'] as $name) {
                $key = mb_strtolower(trim($name));
                $other = $seen[$key] ?? '';
                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    "\"{$name}\" is claimed by both \"{$other}\" and \"{$entry['product']}\".",
                );
                $seen[$key] = $entry['product'];
            }
        }

        $this->assertCount(37, $seen);
    }
}
