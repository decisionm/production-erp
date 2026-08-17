<?php

namespace Tests\Feature\Production;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The CEC golden harness (Phase 5.7, P5.7-02). The CEC's FORMAT is BLOCKED —
 * SOURCE DOCUMENT REQUIRED: no sample exists in the repo and no layout is
 * invented here. This test reads tests/fixtures/cec/*.golden.csv IF present
 * and, for every cell the sample's reading guide maps, asserts
 *
 *     the owner's sample == the CEC == the Shift Summary == Completed Production
 *
 * — the CEC data (GET /production/cec) against the sample cell, and the
 * CEC's own blocks against the two endpoints it is composed from. When no
 * sample is on file it SKIPS, with the one message, and asserts nothing:
 * the format authority is the owner's. See tests/fixtures/cec/README.md for
 * what a golden file is and how its reading guide is written the day one
 * lands.
 */
class CecGoldenTest extends TestCase
{
    use RefreshDatabase;

    public const SKIP_MESSAGE = "CEC sample not on file — format authority is the owner's";

    private const FIXTURES = 'tests/fixtures/cec';

    /** The batch figures the CEC reads off an entries-index row, CEC key → index path. */
    private const BATCH_FIGURES = [
        'batch_number' => 'batch_number',
        'expected_pieces' => 'metrics.expected_pieces',
        'actual_pieces' => 'metrics.actual_pieces',
        'good_production_kg' => 'metrics.good_production_kg',
        'rejection_kg' => 'metrics.rejection_kg_production',
        'rejection_kg_qc' => 'metrics.rejection_kg_qc',
        'efficiency_pct' => 'metrics.efficiency_pct',
        'expected_boxes' => 'metrics.expected_boxes',
        'packs' => 'no_of_box',
        'downtime_minutes_total' => 'metrics.downtime_minutes_total',
        'approval_status' => 'status',
        'tally_status' => 'tally.status',
    ];

    public function test_the_owners_cec_sample_is_the_cec_is_the_shift_summary_is_completed_production(): void
    {
        $samples = glob(base_path(self::FIXTURES.'/*.golden.csv')) ?: [];
        sort($samples);

        // Anything dropped into the fixture directory that is not README.md
        // or one of the recognised triples is the owner's sample under the
        // wrong name — a call to name it, never something to skip past.
        $strays = array_values(array_filter(
            glob(base_path(self::FIXTURES.'/*')) ?: [],
            fn (string $path): bool => basename($path) !== 'README.md'
                && ! preg_match('/\.(golden\.csv|golden\.json|seed\.php)$/', $path),
        ));
        $this->assertSame([], array_map('basename', $strays), 'unrecognised file(s) in tests/fixtures/cec — name the owner\'s sample <name>.golden.csv and write its reading guide <name>.golden.json (tests/fixtures/cec/README.md)');

        if ($samples === []) {
            $this->markTestSkipped(self::SKIP_MESSAGE);
        }

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        foreach ($samples as $sample) {
            $this->assertSampleHolds($sample);
        }
    }

    private function assertSampleHolds(string $sample): void
    {
        $name = basename($sample, '.golden.csv');
        $guidePath = dirname($sample)."/{$name}.golden.json";

        // A sample on file with no reading guide is a call to transcribe it,
        // never something to pass over silently.
        $this->assertFileExists($guidePath, "CEC sample on file ({$name}.golden.csv) but its reading guide {$name}.golden.json is not — transcribe the owner's layout into it (tests/fixtures/cec/README.md).");

        $guide = json_decode((string) file_get_contents($guidePath), true, 512, JSON_THROW_ON_ERROR);
        foreach (['production_date', 'seed', 'cells'] as $key) {
            $this->assertArrayHasKey($key, $guide, "{$name}.golden.json: `{$key}` is required");
        }
        $this->assertArrayHasKey('shift_id', $guide, "{$name}.golden.json: `shift_id` is required (null for the whole day)");

        // The day's records, as the ERP holds them — the seed is the guide's.
        $seedPath = dirname($sample).'/'.$guide['seed'];
        $this->assertFileExists($seedPath, "{$name}.golden.json names a seed that is not on file: {$guide['seed']}");
        $seed = require $seedPath;
        $this->assertIsCallable($seed, "{$guide['seed']} must return a Closure(): void");
        $seed();

        $query = ['date' => $guide['production_date']] + ($guide['shift_id'] === null ? [] : ['shift_id' => (int) $guide['shift_id']]);
        $cec = $this->getJson('/api/v1/production/cec?'.http_build_query($query))->assertOk()->json('data');

        // The sample == the CEC, cell for cell the guide maps.
        $grid = $this->grid($sample);
        $this->assertNotEmpty($guide['cells'], "{$name}.golden.json maps no cells");
        foreach ($guide['cells'] as $i => $cell) {
            foreach (['row', 'column', 'cec'] as $key) {
                $this->assertArrayHasKey($key, $cell, "{$name}.golden.json cells[{$i}]: `{$key}` is required");
            }
            $this->assertArrayHasKey($cell['row'], $grid, "{$name}.golden.csv has no row {$cell['row']}");
            $this->assertArrayHasKey($cell['column'], $grid[$cell['row']], "{$name}.golden.csv row {$cell['row']} has no column {$cell['column']}");

            $expected = trim((string) $grid[$cell['row']][$cell['column']]);
            $actual = Arr::get($cec, $cell['cec'], new \stdClass);
            $this->assertNotInstanceOf(\stdClass::class, $actual, "{$name}: the CEC has no `{$cell['cec']}`");
            $this->assertCellEquals($expected, $actual, "{$name} row {$cell['row']} col {$cell['column']} ↔ cec {$cell['cec']}");
        }

        // The CEC == the Shift Summary == Completed Production, for the
        // sample's date and scope.
        foreach ($cec['shifts'] as $block) {
            $summary = $this->getJson('/api/v1/production/shift-summaries/report?'.http_build_query([
                'production_date' => $guide['production_date'], 'shift_id' => $block['shift']['id'],
            ]))->assertOk()->json('data');
            $this->assertSame($summary, $block['summary'], "{$name}: the {$block['shift']['name']} summary is the Shift Summary report");

            $index = collect($this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
                'production_date' => $guide['production_date'], 'shift_id' => $block['shift']['id'],
                'batch_status' => 'completed', 'per_page' => 100,
            ]))->assertOk()->json('data'))->keyBy('id');

            foreach ($block['machines'] as $machine) {
                foreach ($machine['batches'] as $batch) {
                    $this->assertTrue($index->has($batch['entry_id']), "{$name}: entry {$batch['entry_id']} is on the CEC but not on Completed Production");
                    foreach (self::BATCH_FIGURES as $key => $path) {
                        $this->assertSame(Arr::get($index[$batch['entry_id']], $path), $batch[$key], "{$name}: entry {$batch['entry_id']} {$key}");
                    }
                }
            }
        }
        if ($cec['day'] !== null) {
            $summary = $this->getJson('/api/v1/production/shift-summaries/report?'.http_build_query([
                'production_date' => $guide['production_date'],
            ]))->assertOk()->json('data');
            $this->assertSame($summary, $cec['day']['summary'], "{$name}: the day summary is the whole-day Shift Summary report");
        }
    }

    /**
     * A sample cell against a CEC value: numerically when both read as
     * numbers (the owner's sheet may print 70.56 where the CEC carries
     * '70.5600' — the same figure), as text otherwise; a null CEC value
     * matches only an empty cell.
     */
    private function assertCellEquals(string $expected, mixed $actual, string $label): void
    {
        if ($actual === null) {
            $this->assertSame('', $expected, "{$label}: the CEC has no figure here, the sample must be blank");

            return;
        }

        if (is_bool($actual) || is_array($actual)) {
            $this->assertSame($expected, json_encode($actual), $label);

            return;
        }

        $actual = (string) $actual;
        if (is_numeric($expected) && is_numeric($actual)) {
            $this->assertSame(0, bccomp($expected, $actual, 8), "{$label}: sample {$expected} vs CEC {$actual}");

            return;
        }

        $this->assertSame($expected, $actual, $label);
    }

    /**
     * The sample as a grid of cells — every line CSV-split, nothing else
     * assumed about it.
     *
     * @return list<list<string>>
     */
    private function grid(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }
}
