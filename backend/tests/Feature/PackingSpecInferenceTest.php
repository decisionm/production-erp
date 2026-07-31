<?php

namespace Tests\Feature;

use App\Modules\Production\Data\PackingSpecInferences;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filling the packing-material specs the workbook left blank.
 *
 * The owner asked for this in their own words — "check the product name and
 * try to fill the carton and tray and pouch film for the blank one" — with
 * their own example: 375ML KIDNEY takes the 500ML KIDNEY's carton.
 *
 * What is being protected here is not the fills themselves but the three rules
 * around them, because the columns stop being reference text the moment the
 * packing-materials build lands and start ordering cartons:
 *
 *  1. an inference NEVER overwrites a stated value;
 *  2. every inferred value carries the row it came from, so it can be told
 *     apart from one the factory wrote;
 *  3. a re-import of the workbook reproduces the fill instead of blanking it.
 */
class PackingSpecInferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function workbookRows(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/product-master-rows.json')), true);
    }

    private function standard(array $attributes = []): ProductionStandard
    {
        return ProductionStandard::create(array_merge([
            'source_product_name' => '375ML KIDNEY',
            'cavities' => 2,
            'unit_weight_grams' => 26,
            'cycle_time' => 16.5,
            'status' => 'draft',
            'source' => 'ERPPRO29072026',
            'source_reference' => '60',
        ], $attributes));
    }

    public function test_the_backfill_fills_a_blank_spec_and_records_where_it_came_from(): void
    {
        $standard = $this->standard();

        $this->assertSame(1, PackingSpecInferences::backfill());

        $standard->refresh();
        $this->assertSame('HM 30.5*49', $standard->carton_spec);

        // The value is clean — no "(inferred)" baked into the string somebody
        // is going to order cartons against.
        $this->assertStringNotContainsString('inferred', mb_strtolower((string) $standard->carton_spec));

        $provenance = $standard->spec_provenance['carton_spec'];
        $this->assertTrue($provenance['inferred']);
        $this->assertSame('58', $provenance['from_source_reference']);
        $this->assertSame('500ML KIDNEY', $provenance['from_product']);
        $this->assertNotSame('', trim((string) $provenance['reason']));

        // Only the column that was filled is marked. The other two are still
        // blank and must not claim a provenance they do not have.
        $this->assertSame(['carton_spec'], array_keys($standard->spec_provenance));
    }

    public function test_a_stated_spec_is_never_overwritten(): void
    {
        // Somebody typed the real carton in after the import. That answer
        // outranks anything this software worked out from the neighbours.
        $standard = $this->standard(['carton_spec' => 'HAND ENTERED 40 X 50']);

        $this->assertSame(0, PackingSpecInferences::backfill());

        $standard->refresh();
        $this->assertSame('HAND ENTERED 40 X 50', $standard->carton_spec);
        $this->assertNull($standard->spec_provenance);
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $standard = $this->standard();

        $this->assertSame(1, PackingSpecInferences::backfill());
        $standard->refresh();
        $before = [$standard->carton_spec, $standard->spec_provenance, (string) $standard->updated_at];

        $this->assertSame(0, PackingSpecInferences::backfill(), 'A second run has nothing left to fill.');

        $standard->refresh();
        $this->assertSame($before, [$standard->carton_spec, $standard->spec_provenance, (string) $standard->updated_at]);
    }

    public function test_a_row_with_no_family_evidence_stays_blank(): void
    {
        // SL 80, 750ML KIDNEY: blank carton, and the only candidate values are
        // its own pouch string moved sideways or a weight-mismatched family
        // value. Both are guesses about a carton, not evidence about one.
        $standard = $this->standard([
            'source_product_name' => '750ML KIDNEY',
            'unit_weight_grams' => 39,
            'cycle_time' => 15.5,
            'source_reference' => '80',
            'pouch_spec' => 'HM 30 X 49',
        ]);

        PackingSpecInferences::backfill();

        $standard->refresh();
        $this->assertNull($standard->carton_spec);
        $this->assertNull($standard->tray_spec);
        $this->assertNull($standard->spec_provenance);
    }

    public function test_the_inference_only_applies_to_the_row_it_was_written_for(): void
    {
        // Same SL, different product — a reordered sheet must not put the
        // KIDNEY's carton on another bottle.
        $standard = $this->standard(['source_product_name' => '500ML ROUND']);

        $this->assertSame(0, PackingSpecInferences::backfill());

        $this->assertNull($standard->refresh()->carton_spec);
    }

    public function test_reimporting_the_workbook_reproduces_the_fills_instead_of_blanking_them(): void
    {
        $import = app(ProductionStandardImportService::class);
        $import->import($this->workbookRows(), false, null);

        $kidney = ProductionStandard::where('source_reference', '60')->firstOrFail();
        $this->assertSame('HM 30.5*49', $kidney->carton_spec);
        $this->assertSame('58', $kidney->spec_provenance['carton_spec']['from_source_reference']);

        // The import writes the specs straight from the sheet, so without the
        // inference living inside the importer too this second pass would put
        // the workbook's blank right back over the filled cell.
        $import->import($this->workbookRows(), false, null);

        $kidney->refresh();
        $this->assertSame('HM 30.5*49', $kidney->carton_spec);
        $this->assertTrue($kidney->spec_provenance['carton_spec']['inferred']);
    }

    public function test_every_fill_lands_on_the_row_it_names_and_nowhere_else(): void
    {
        app(ProductionStandardImportService::class)->import($this->workbookRows(), false, null);

        foreach (PackingSpecInferences::INFERENCES as $slNo => $inference) {
            $standard = ProductionStandard::where('source_reference', (string) $slNo)->firstOrFail();

            $this->assertSame(
                $inference['product'],
                $standard->source_product_name,
                "SL {$slNo} is filed against a different product than the inference names.",
            );

            foreach ($inference['fills'] as $column => $fill) {
                $this->assertSame($fill['value'], $standard->{$column}, "SL {$slNo} {$column}");
                $this->assertTrue($standard->spec_provenance[$column]['inferred']);
            }
        }
    }

    public function test_a_spec_the_sheet_states_carries_no_provenance(): void
    {
        app(ProductionStandardImportService::class)->import($this->workbookRows(), false, null);

        // SL 3 states all three columns itself.
        $stated = ProductionStandard::where('source_reference', '3')->firstOrFail();

        $this->assertSame('30ML', $stated->carton_spec);
        $this->assertNull($stated->spec_provenance, 'A value read off the sheet is not an inference.');
    }
}
