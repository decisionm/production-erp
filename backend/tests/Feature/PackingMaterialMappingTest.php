<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\PackingItemMatcher;
use App\Modules\Production\Services\PackingMaterialMappingService;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PACKING MATERIALS — the carton, the tray, the film and the tape, calculated
 * the way resin and masterbatch already are.
 *
 * The owner asked for exactly this on 31 Jul: "along with Resin and master
 * batch, all other packing consumption also need to calculate, which carton
 * box and tray film pouch and tape under packing consumption." Their standing
 * rule frames every test here the same way it frames MasterbatchDosingTest:
 * the supervisor's submitted line is the truth, and a mapping only ever
 * SUGGESTS. So this suite proves two separate things and must never confuse
 * them:
 *
 *   1. the suggestion is right — the item, the factor, the unit, and the
 *      "no mapping means no prefill" case, and
 *   2. the suggestion is powerless — what was submitted is what is stored,
 *      and what is stored is what Tally receives.
 *
 * It also proves a third thing the masterbatch build did not have to: that
 * the SEED refuses to answer what it cannot prove. A wrong carton mapped once
 * is a wrong carton on every dispatch of that product, so an ambiguous spec
 * has to come back empty and named, not resolved to whichever row sorted
 * first.
 */
class PackingMaterialMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The owner's tape figures for the three cartons this suite uses
     * (TapeMetresPerBox, 31 Jul 2026). Written once, here, so no test
     * silently invents a fourteenth row.
     */
    private const TAPE_170_ROUND = '2.2900';

    private const TAPE_100_ROUND = '2.2960';

    /** @var array<string, Item> the factory's real Tally packing catalogue, by name */
    private array $catalogue = [];

    private Warehouse $rmStore;

    private Warehouse $fg;

    private Item $bottle;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        // Packing-material mapping is what this suite pins; approvals here are
        // scaffolding to reach the voucher. The quality gate is covered in
        // BatchQualityStageTest.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);

        $this->bottle = Item::create([
            'sku' => 'BTL-170', 'name' => '170ML Round Bottle', 'uom' => 'NOS',
            'nominal_weight_grams' => '9.0000',
        ]);
        $this->resin = Item::create(['sku' => 'PET-CHIPS', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs']);

        // The factory's real packing items, spelled exactly as their own July
        // Stock Journals spell them — double spaces and all. The spellings
        // ARE the fixture: the seed has to survive "60 Ml Master Box" next to
        // "60 Ml Tray", and "500ML IFF Tray" next to "500ML Tray IFF".
        //
        // uom is 'NOS' across the board on purpose, INCLUDING the two film
        // items Tally actually moves in Kgs. That is what this factory's item
        // master really looks like, and a build that filtered packing items by
        // uom would find no film at all on the live database.
        foreach ([
            '15ml Round Master Box', '30ml Master Box', '60 Ml Master Box', '100 Ml Master Box',
            '170 Ml Master Box', '200 Ml Round Master Box', '200 Ml Brute Master Box',
            '300ml Emcure Master Carton', '500ml Round Master Box',
            '60 Ml Tray', '100 Ml Tray', '200 Ml Brute Tray', '500ml Tray',
            // Two catalogue rows naming one physical tray. Live, and not in
            // the owner's own list of eighteen — which is the point.
            '500ML IFF Tray', '500ML Tray IFF',
            'LDPE  COVER (28.5x38x120G)', 'LDPE  COVER (30x49x120G)',
            'Hm Polythene Bags -  30.5 x 49 x 200G',
            'Packing Tape - Transparent', 'Packing Tape Green',
            '500 Ml PAD',
        ] as $index => $name) {
            $this->catalogue[$name] = Item::create([
                'sku' => 'PACK-'.($index + 1), 'name' => $name, 'uom' => 'NOS',
            ]);
        }
    }

    private function actingAsProduction(string $permission = 'production.manage'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * One factory standard, carrying the workbook's spec strings.
     *
     * @param  array<string, string>  $specs
     * @param  array<string, mixed>|null  $provenance
     */
    private function standard(Item $item, array $specs, ?array $provenance = null): ProductionStandard
    {
        return ProductionStandard::create([
            'item_id' => $item->id,
            'source_product_name' => $item->name,
            'cavities' => 4,
            'unit_weight_grams' => '9.0000',
            'cycle_time' => '12.00',
            'status' => 'approved',
            'source_reference' => '1',
            'carton_spec' => $specs['carton'] ?? null,
            'tray_spec' => $specs['tray'] ?? null,
            'pouch_spec' => $specs['pouch'] ?? null,
            'spec_provenance' => $provenance,
        ]);
    }

    /**
     * The five workbook rows this suite seeds against — chosen because
     * between them they hit every outcome the seed can produce: a clean
     * match, a spelling the catalogue never uses, a spec with no item at all,
     * a spec with three items, a spec with two items naming one material, and
     * a poly-bag string sitting in the carton column.
     */
    private function seedFixtureStandards(): void
    {
        $this->standard($this->bottle, ['carton' => '170ML', 'tray' => '60ML', 'pouch' => '750*610']);

        $tablet = Item::create(['sku' => 'BTL-120CC', 'name' => '120CC Tablet Container', 'uom' => 'NOS']);
        $this->standard($tablet, ['carton' => '300ML ROUND', 'tray' => '500ML', 'pouch' => 'LD 28.5 X 38']);

        $brute = Item::create(['sku' => 'BTL-200B', 'name' => '200ML Brute Bottle', 'uom' => 'NOS']);
        $this->standard($brute, ['carton' => '200ML BRUTE', 'tray' => '200ML BRUTE', 'pouch' => 'HM 30 X 49']);

        $boston = Item::create(['sku' => 'BTL-100B', 'name' => '100ML Boston Bottle', 'uom' => 'NOS']);
        $this->standard($boston, ['carton' => '100ML ROUND', 'tray' => '500ML IFF']);

        $kidney = Item::create(['sku' => 'BTL-500K', 'name' => '500ML Kidney Bottle', 'uom' => 'NOS']);
        $this->standard($kidney, ['carton' => 'HM 30.5*49']);
    }

    /**
     * Re-runs the data migration against items and standards that exist NOW.
     * Under RefreshDatabase both tables are empty when migrations run, so the
     * seed is a no-op during the normal test boot — which is exactly why it
     * has to be exercised deliberately. Without this, the one line that maps
     * the factory's packing materials on a live server is never executed by
     * any test.
     */
    private function runSeedMigration(): void
    {
        $migration = require database_path('migrations/2026_08_01_090002_seed_packing_material_mappings.php');
        $migration->up();
    }

    /** @return array<string, string> kind|spec => item name, for the rows that were mapped */
    private function seededIndex(): array
    {
        return PackingMaterialMapping::query()
            ->with('item')
            ->get()
            ->mapWithKeys(fn (PackingMaterialMapping $row) => [
                $row->spec_kind.'|'.$row->spec_value => (string) $row->item?->name,
            ])
            ->all();
    }

    // ------------------------------------------------ (1) editable master ---

    public function test_a_mapping_round_trips_through_the_api(): void
    {
        $this->actingAsProduction();

        // Nothing mapped yet: an EMPTY list, which is the honest "no answer"
        // the floor must render as a blank line.
        $this->getJson('/api/v1/production/packing-material-mappings')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'carton',
            'spec_value' => '170ML',
            'item_id' => $this->catalogue['170 Ml Master Box']->id,
            'note' => 'Factory, 1 Aug — owner confirmed on WhatsApp.',
        ])
            ->assertOk()
            ->assertJsonPath('data.spec_kind', 'carton')
            ->assertJsonPath('data.spec_value', '170ML')
            ->assertJsonPath('data.item.name', '170 Ml Master Box')
            // One carton packed is one carton consumed — the factor is a
            // literal 1 and there is no column for it to drift out of.
            ->assertJsonPath('data.factor', '1')
            ->assertJsonPath('data.unit', 'nos')
            ->assertJsonPath('data.basis', 'per_carton')
            ->assertJsonPath('data.quantity_basis', 'cartons')
            // Provenance travels with the answer: who said it and who typed
            // it. A mapping nobody can attribute is one nobody can defend
            // when the packing variance is questioned weeks later.
            ->assertJsonPath('data.note', 'Factory, 1 Aug — owner confirmed on WhatsApp.')
            ->assertJsonPath('data.set_by', auth()->user()->name);

        // Read back on a fresh request: persisted, not per-request state.
        $this->getJson('/api/v1/production/packing-material-mappings?spec_kind=carton')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.name', '170 Ml Master Box');

        // The spec lookup folds case and spacing, because the workbook spells
        // one spec two ways across neighbouring rows.
        $this->getJson('/api/v1/production/packing-material-mappings?spec_value=170ml')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_correcting_a_mapping_replaces_it_instead_of_stacking_a_second_row(): void
    {
        $this->actingAsProduction();

        foreach (['170 Ml Master Box', '200 Ml Round Master Box'] as $name) {
            $this->postJson('/api/v1/production/packing-material-mappings', [
                'spec_kind' => 'carton',
                'spec_value' => '170ML',
                'item_id' => $this->catalogue[$name]->id,
            ])->assertOk()->assertJsonPath('data.item.name', $name);
        }

        // One row, not two. Two rows for one spec would leave the floor's
        // prefill depending on which one a query happened to find first.
        $this->assertSame(1, PackingMaterialMapping::query()->count());
    }

    public function test_withdrawing_a_mapping_returns_the_floor_to_no_prefill_and_can_be_re_answered(): void
    {
        $this->actingAsProduction();

        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'tray', 'spec_value' => '60ML',
            'item_id' => $this->catalogue['60 Ml Tray']->id,
        ])->assertOk();

        $mapping = PackingMaterialMapping::query()->sole();
        $this->deleteJson("/api/v1/production/packing-material-mappings/{$mapping->id}")->assertOk();

        $this->getJson('/api/v1/production/packing-material-mappings')->assertJsonCount(0, 'data');
        // Soft-deleted, not erased: shifts prefilled from it while it applied
        // stay explainable.
        $this->assertSoftDeleted('packing_material_mappings', ['id' => $mapping->id]);

        // And re-answering RESTORES that row rather than failing on the
        // unique index the withdrawn row still occupies.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'tray', 'spec_value' => '60ML',
            'item_id' => $this->catalogue['60 Ml Tray']->id,
        ])->assertOk()->assertJsonPath('data.id', $mapping->id);

        $this->assertSame(1, PackingMaterialMapping::query()->count());
    }

    public function test_a_dose_may_only_be_set_on_the_kind_it_belongs_to(): void
    {
        $this->actingAsProduction();

        // Grams belong to film. A carton row carrying one would start
        // suggesting a weight for cardboard the moment somebody widened a
        // query.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'carton', 'spec_value' => '170ML',
            'item_id' => $this->catalogue['170 Ml Master Box']->id,
            'grams_per_piece' => '120',
        ])->assertStatus(422)->assertJsonValidationErrors('grams_per_piece');

        // Metres belong to tape.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'pouch_film', 'spec_value' => 'LD 28.5 X 38',
            'item_id' => $this->catalogue['LDPE  COVER (28.5x38x120G)']->id,
            'metres_per_box' => '2.29',
        ])->assertStatus(422)->assertJsonValidationErrors('metres_per_box');

        // Zero is not "no dose" — no dose is expressed by leaving the figure
        // out, never by a zero that would tell the floor a carton takes no
        // film.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'pouch_film', 'spec_value' => 'LD 28.5 X 38',
            'item_id' => $this->catalogue['LDPE  COVER (28.5x38x120G)']->id,
            'grams_per_piece' => '0',
        ])->assertStatus(422)->assertJsonValidationErrors('grams_per_piece');

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }

    public function test_reading_the_mappings_needs_only_view_but_setting_them_needs_manage(): void
    {
        // A supervisor with production.view must be able to SEE what is
        // mapped — it drives the lines in front of them.
        $this->actingAsProduction('production.view');

        $this->getJson('/api/v1/production/packing-material-mappings')->assertOk();

        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'carton', 'spec_value' => '170ML',
            'item_id' => $this->catalogue['170 Ml Master Box']->id,
        ])->assertForbidden();

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }

    // ------------------------------------------- (2) the deploy-time seed ---

    public function test_the_seed_maps_only_the_specs_the_catalogue_can_prove(): void
    {
        $this->seedFixtureStandards();

        $result = app(PackingMaterialMappingService::class)->seedFromCatalogue();

        // Compared key-sorted, so this assertion is about WHICH specs
        // resolved and to what — never about the order rows happened to be
        // inserted in.
        $expected = [
            // Contained verbatim in exactly one carton name.
            'carton|170ML' => '170 Ml Master Box',
            'carton|200ML BRUTE' => '200 Ml Brute Master Box',
            // Tally never spelled ROUND on this box, and it is the only 100
            // carton in the catalogue — so the qualifier has nothing to
            // discriminate between and the size answers it.
            'carton|100ML ROUND' => '100 Ml Master Box',
            'tray|60ML' => '60 Ml Tray',
            'tray|200ML BRUTE' => '200 Ml Brute Tray',
            // Matched on its DIMENSIONS, which is the only thing a film name
            // and a film spec have in common.
            'pouch_film|LD 28.5 X 38' => 'LDPE  COVER (28.5x38x120G)',
            // Tape is keyed by the carton it seals, and seeded to the one
            // Transparent tape in the catalogue.
            'tape|170ML' => 'Packing Tape - Transparent',
            'tape|200ML BRUTE' => 'Packing Tape - Transparent',
            'tape|100ML ROUND' => 'Packing Tape - Transparent',
        ];

        $actual = $this->seededIndex();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);

        // ...and every other spec came back a QUESTION, named, with a reason
        // a person can act on.
        $missed = collect($result['missed'])->mapWithKeys(fn (array $row) => [$row['kind'].'|'.$row['spec'] => $row['reason']]);

        $this->assertEqualsCanonicalizing([
            'carton|300ML ROUND', 'carton|HM 30.5*49',
            'tray|500ML', 'tray|500ML IFF',
            'pouch_film|750*610', 'pouch_film|HM 30 X 49',
            'tape|300ML ROUND', 'tape|HM 30.5*49',
        ], $missed->keys()->all());

        // The 120CC container's carton column reads "300ML ROUND" and the
        // only 300 carton in the catalogue is the EMCURE one. Mapping it
        // would put a pharma customer's carton on a tablet container.
        $this->assertStringContainsString('300', $missed['carton|300ML ROUND']);

        // "500ML" names three trays here. One of them is the answer and this
        // seed may not pick which.
        $this->assertStringContainsString('3 catalogue items match it', $missed['tray|500ML']);

        // "500ML IFF Tray" and "500ML Tray IFF" are one physical tray entered
        // twice in Tally. Containment picks the first happily; that is a
        // master-data question, not a pick.
        $this->assertStringContainsString('name the same material', $missed['tray|500ML IFF']);

        // A poly-bag string sitting in the CARTON column is bag-direct
        // packing with no carton step — not a carton this seed should invent.
        $this->assertStringContainsString('no catalogue name contains it', $missed['carton|HM 30.5*49']);

        // The tape table has no row paired to a 300ML ROUND or an HM bag,
        // and pairing one would assert a box those products are not packed in.
        $this->assertStringContainsString('metres-per-box table has no row', $missed['tape|300ML ROUND']);
    }

    public function test_the_seed_parses_the_film_weight_out_of_the_items_own_name(): void
    {
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $film = PackingMaterialMapping::query()->where('spec_kind', 'pouch_film')->sole();

        // "LDPE  COVER (28.5x38x120G)" is 28.5 by 38 at 120 grams a piece.
        // The dimensions made the match; the weight came free with it.
        $this->assertSame('120.0000', (string) $film->grams_per_piece);
        $this->assertSame('120.0000', $film->factor());
        // grams, NOT kg — Tally weighs the roll in Kgs while the name states
        // one piece. Confusing the two is a 1000x error on a real voucher.
        $this->assertSame('g', $film->basis()['factor_unit']);
        $this->assertSame('kg', $film->basis()['unit']);
        // Cartons, not pouches: the film wraps a carton's contents once
        // (owner, 31 Jul).
        $this->assertSame('cartons', $film->basis()['quantity_basis']);
        $this->assertStringContainsString("parsed from the item's own name", (string) $film->note);

        // The other LDPE cover, 30x49, is a real catalogue item and is NOT
        // what "LD 28.5 X 38" asked for.
        $this->assertSame($this->catalogue['LDPE  COVER (28.5x38x120G)']->id, (int) $film->item_id);
    }

    public function test_the_seed_takes_the_tape_factor_from_the_owners_metres_per_box_table(): void
    {
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $tape = PackingMaterialMapping::query()->where('spec_kind', 'tape')->get()
            ->mapWithKeys(fn (PackingMaterialMapping $row) => [$row->spec_value => (string) $row->metres_per_box]);

        // The owner's own figures, keyed by the CARTON the tape seals — not
        // invented, not interpolated, and not the same for two box sizes.
        $this->assertSame(self::TAPE_170_ROUND, $tape['170ML']);
        $this->assertSame(self::TAPE_100_ROUND, $tape['100ML ROUND']);
        $this->assertSame('2.2260', $tape['200ML BRUTE']);

        $row = PackingMaterialMapping::query()->where('spec_value', '170ML')->where('spec_kind', 'tape')->sole();
        $this->assertSame(self::TAPE_170_ROUND, $row->factor());
        $this->assertSame('m', $row->basis()['unit']);
        // The open question travels in the row itself, so nobody reads the
        // metres as a Tally quantity by accident.
        $this->assertStringContainsString('still open', (string) $row->note);
    }

    public function test_the_seed_is_idempotent_and_never_overwrites_an_answer_a_person_gave(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();

        // The factory answers one of the questions the seed cannot: "500ML"
        // is the plain tray, not either of the IFF rows.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'tray', 'spec_value' => '500ML',
            'item_id' => $this->catalogue['500ml Tray']->id,
            'note' => 'Factory, 1 Aug: the plain tray, not the IFF ones.',
        ])->assertOk();

        $this->runSeedMigration();
        $countAfterFirstRun = PackingMaterialMapping::query()->count();

        // A re-run (migrate:fresh, a re-deploy) stacks nothing.
        $this->runSeedMigration();
        $this->assertSame($countAfterFirstRun, PackingMaterialMapping::query()->count());

        // ...and the person's answer is untouched — not restated, not
        // reverted to "ambiguous, no row".
        $answered = PackingMaterialMapping::query()->where('spec_kind', 'tray')->where('spec_value', '500ML')->sole();
        $this->assertSame($this->catalogue['500ml Tray']->id, (int) $answered->item_id);
        $this->assertStringContainsString('Factory, 1 Aug', (string) $answered->note);
    }

    public function test_the_migration_logs_every_spec_it_could_not_answer(): void
    {
        $this->seedFixtureStandards();
        Log::spy();

        $this->runSeedMigration();

        // A miss is the deliverable, not a failure — but only if somebody is
        // told. Each warning names the spec specifically enough to act on.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'carton "300ML ROUND" NOT mapped')
                && str_contains($message, 'POST /api/v1/production/packing-material-mappings'))
            ->atLeast()->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'tray "500ML" NOT mapped'))
            ->atLeast()->once();
    }

    public function test_the_seed_does_nothing_when_the_catalogue_has_no_packing_items(): void
    {
        // An instance whose Tally spells everything differently must be able
        // to answer in the app — a migration guessing which box was meant is
        // worse than no row at all.
        foreach ($this->catalogue as $item) {
            $item->forceDelete();
        }

        $this->seedFixtureStandards();
        $result = app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $this->assertSame(0, PackingMaterialMapping::query()->count());
        $this->assertSame([], $result['seeded']);
        $this->assertNotSame([], $result['missed']);
    }

    // ----------------------------------------- (3) the suggestion the floor
    // actually reads -----------------------------------------------------

    public function test_a_bag_packed_product_gets_the_bag_and_nothing_else(): void
    {
        // THE FACTORY'S RULE, verbatim (05-Aug): "when HM, no need to use the
        // tray or pouch and other packing material." Their own sheet proves it
        // without being asked — all 17 rows whose carton column holds an HM or
        // LD bag carry no tray spec and no film spec at all.
        //
        // Specs are set here for tray and film DELIBERATELY, so the rule is
        // tested rather than the fixture: even when the columns are filled, a
        // bag-packed product must not quote them. Every line after the bag is
        // dosed per CARTON, and a bag-packed product has no carton — so a tray,
        // film or tape line would be a real material quoted against a container
        // that does not exist.
        $standard = $this->standard($this->bottle, [
            'carton' => 'HM 30.5*49',
            'tray' => '60ML',
            'pouch' => '750*610',
        ]);

        $kinds = collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))
            ->pluck('kind')->all();

        $this->assertSame(['carton'], $kinds, 'A bag is the whole pack — no tray, no film, no tape.');
    }

    public function test_the_bag_rule_reads_the_value_not_the_column(): void
    {
        // The workbook files a bag under TRAY on one row and uses its CARTON
        // column for both carton sizes and bag sizes. So the column cannot say
        // what kind of thing a spec names — only the value can. LD written
        // closed up ("LD28.5 X 39", one real row) counts too.
        foreach (['HM 30.5*49', 'LD 28.5 X 38', 'LD 30 X 49', 'LD28.5 X 39', 'hm 30 x 49'] as $bag) {
            // Unsaved: the rule reads three spec columns and nothing else, and
            // persisting five near-identical standards would only trip the
            // production_standards uniqueness that exists for a different reason.
            $standard = new ProductionStandard(['carton_spec' => $bag, 'tray_spec' => '60ML']);

            $this->assertSame(
                ['carton'],
                collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))->pluck('kind')->all(),
                "\"{$bag}\" is a bag and must suppress every other packing line.",
            );
        }

        // And an ordinary carton is untouched by the rule.
        $standard = new ProductionStandard(['carton_spec' => '170ML', 'tray_spec' => '60ML']);
        $this->assertSame(
            ['carton', 'tray', 'tape'],
            collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))->pluck('kind')->all(),
        );
    }

    public function test_a_film_on_a_tray_packed_product_is_counted_per_tray(): void
    {
        // ONE POUCH PER TRAY. The factory (05-Aug): "along with the tray, the
        // pouch also needs to calculate — five trays, five pouches." Their
        // arithmetic backs it in all 55 tray rows of the workbook:
        // bottles/tray x trays/box = bottles/box, so a box of 810 is five trays
        // of 162 and each tray is covered.
        //
        // Dosed per carton — which it was — one film is quoted for a box that
        // really consumes five. An under-count of four fifths on every
        // tray-packed shift, invisible in Tally until the film shelf is counted.
        $standard = $this->standard($this->bottle, [
            'carton' => '170ML', 'tray' => '60ML', 'pouch' => '750*610',
        ]);

        $film = collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))
            ->firstWhere('kind', 'pouch_film');

        $this->assertNotNull($film);
        $this->assertSame('trays', $film['quantity_basis']);
        $this->assertSame('per_tray', $film['basis']);
        // The MATERIAL and its units are untouched — only the container it is
        // counted against changed. Film is still grams per piece into kg.
        $this->assertSame('kg', $film['unit']);
        $this->assertSame('g', $film['factor_unit']);
    }

    public function test_a_film_on_a_product_with_no_tray_stays_counted_per_carton(): void
    {
        // A pouch-packed product has no tray to cover, so its film keeps the
        // carton basis. The re-base is for the tray case and nothing else.
        $standard = $this->standard($this->bottle, ['carton' => '170ML', 'pouch' => '750*610']);

        $film = collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))
            ->firstWhere('kind', 'pouch_film');

        $this->assertSame('cartons', $film['quantity_basis']);
        $this->assertSame('per_carton', $film['basis']);
    }

    public function test_the_preview_carries_a_packing_line_per_material_with_its_factor(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $response = $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
            ->assertOk();

        $lines = collect($response->json('data.suggested_packing'))
            ->keyBy('kind');

        // Four materials for this product: the box, the tray, the film that
        // wraps the carton's contents, and the tape that seals it.
        $this->assertSame(['carton', 'tray', 'pouch_film', 'tape'], $lines->keys()->all());

        $this->assertSame('170 Ml Master Box', $lines['carton']['item']['name']);
        $this->assertSame('1', $lines['carton']['factor']);
        $this->assertSame('nos', $lines['carton']['unit']);
        $this->assertSame('cartons', $lines['carton']['quantity_basis']);
        $this->assertSame('per_carton', $lines['carton']['basis']);

        $this->assertSame('60 Ml Tray', $lines['tray']['item']['name']);
        $this->assertSame('1', $lines['tray']['factor']);
        // Trays are counted against TRAYS packed, not cartons — the one place
        // this list changes its multiplier.
        $this->assertSame('trays', $lines['tray']['quantity_basis']);
        $this->assertSame('per_tray', $lines['tray']['basis']);

        $this->assertSame('Packing Tape - Transparent', $lines['tape']['item']['name']);
        $this->assertSame(self::TAPE_170_ROUND, $lines['tape']['factor']);
        $this->assertSame('m', $lines['tape']['unit']);
        // Tape is per CARTON, because tape seals the box.
        $this->assertSame('cartons', $lines['tape']['quantity_basis']);
        $this->assertStringContainsString('still open', $lines['tape']['reason']);

        // NO totals anywhere: the counts are being typed as this is read, and
        // this endpoint is also what Start Batch calls, where nothing has
        // been packed yet. Every entry is a factor and a basis.
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('suggested_quantity', $line);
            $this->assertArrayNotHasKey('quantity', $line);
        }
    }

    public function test_a_spec_with_no_mapping_comes_back_null_with_a_reason_naming_it(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $lines = collect(
            $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
                ->assertOk()
                ->json('data.suggested_packing'),
        )->keyBy('kind');

        // "750*610" is a millimetre string that names no film item in this
        // catalogue, and whether the film a carton takes is driven by these
        // strings or by the carton column's own HM/LD dimension is a question
        // only the factory can settle.
        $this->assertNull($lines['pouch_film']['item']);
        $this->assertNull($lines['pouch_film']['factor']);
        // A null with a reason the screen prints is the point. A plausible
        // guess here reaches a real dispatch.
        $this->assertStringContainsString('750*610', $lines['pouch_film']['reason']);
        $this->assertStringContainsString('no packing-material mapping', $lines['pouch_film']['reason']);
        // The kind's unit still stands — it is a property of the material,
        // not of the item that is missing.
        $this->assertSame('kg', $lines['pouch_film']['unit']);
    }

    public function test_a_mapping_entered_without_its_dose_offers_the_item_and_no_quantity(): void
    {
        $this->actingAsProduction();
        $this->standard($this->bottle, ['carton' => '170ML', 'pouch' => '750*610']);

        // A supervisor may know WHICH film a spec means and not know what one
        // piece weighs. The item is worth offering on its own — it saves the
        // dropdown — but a kg quoted from a weight nobody has given would be
        // a number with nothing behind it.
        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'pouch_film', 'spec_value' => '750*610',
            'item_id' => $this->catalogue['LDPE  COVER (30x49x120G)']->id,
            'note' => 'Factory, 1 Aug: this is the film. Weight per piece to follow.',
        ])->assertOk()->assertJsonPath('data.factor', null);

        $lines = collect(
            $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
                ->assertOk()
                ->json('data.suggested_packing'),
        )->keyBy('kind');

        $this->assertSame('LDPE  COVER (30x49x120G)', $lines['pouch_film']['item']['name']);
        $this->assertNull($lines['pouch_film']['factor']);
        // ...and the reason says which half of the answer is missing, so the
        // blank field is explained rather than just blank.
        $this->assertStringContainsString('per-piece weight is not set', $lines['pouch_film']['reason']);
    }

    public function test_an_inferred_spec_is_still_usable_but_the_reason_says_it_was_inferred(): void
    {
        $this->actingAsProduction();

        // PackingSpecInferences MARKS its fills, it does not quarantine them
        // — but an inferred carton is the one place a wrong box reaches a
        // real dispatch, so the supervisor has to be told which row it came
        // from.
        $inferred = Item::create(['sku' => 'BTL-30L', 'name' => '30ML Round Long Sangam', 'uom' => 'NOS']);
        $this->standard($inferred, ['carton' => '30ML'], [
            'carton_spec' => [
                'inferred' => true,
                'value' => '30ML',
                'from_source_reference' => '3',
                'from_product' => '30ML ROUND',
                'reason' => 'Only row of the 30ML ROUND family that states a carton.',
            ],
        ]);

        $this->postJson('/api/v1/production/packing-material-mappings', [
            'spec_kind' => 'carton', 'spec_value' => '30ML',
            'item_id' => $this->catalogue['30ml Master Box']->id,
        ])->assertOk();

        $lines = collect(
            $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$inferred->id}")
                ->assertOk()
                ->json('data.suggested_packing'),
        )->keyBy('kind');

        // Usable: the item is offered.
        $this->assertSame('30ml Master Box', $lines['carton']['item']['name']);
        // And marked, naming the row the value was taken FROM.
        $this->assertStringContainsString('spec inferred from row 3', $lines['carton']['reason']);
        $this->assertStringContainsString('30ML ROUND', $lines['carton']['reason']);

        // The tape line rides on the same carton spec, so it carries the same
        // warning rather than presenting a stated figure.
        $this->assertStringContainsString('spec inferred from row 3', $lines['tape']['reason']);
    }

    public function test_a_product_with_no_standard_gets_an_empty_packing_list_not_a_row_of_blanks(): void
    {
        $this->actingAsProduction();

        // No standard means the workbook says nothing about how this product
        // is packed. Inventing four blank lines would put questions on the
        // screen the factory never asked.
        $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.suggested_packing');
    }

    // ------------------- (3a) metres are not Nos: the tape line is withheld --
    //
    // The tape suggestion is computed in METRES (100 cartons × 2.29 = 229 m)
    // and the Tally item it points at is counted in Nos. The completion screen
    // used to file that 229 as an ordinary consumption line, so 229 "Nos" of
    // tape were issued against a real store and posted to the live books — a
    // different number about a different thing, with nothing on screen saying
    // so. Whether a Tally "No" is one metre or one whole roll has been open
    // with the factory since the figures arrived (TapeMetresPerBox).
    //
    // These four tests pin the honest answer: the line is SHOWN with its
    // metres and withheld from the payload until the factory settles the unit,
    // and the moment they settle it — either way — it posts.

    /** The suggestion for one kind, off the live preview endpoint. */
    private function previewLine(string $kind): array
    {
        return collect(
            $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
                ->assertOk()
                ->json('data.suggested_packing'),
        )->keyBy('kind')->get($kind);
    }

    public function test_the_tape_line_is_shown_in_metres_and_withheld_from_stock_until_the_unit_is_settled(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $tape = $this->previewLine('tape');

        // Still shown, still the factory's own figure, still in metres — this
        // is not a quarantine. A supervisor who wants to know how much tape a
        // 100-carton run eats can still read it off the screen.
        $this->assertSame('Packing Tape - Transparent', $tape['item']['name']);
        $this->assertSame(self::TAPE_170_ROUND, $tape['factor']);
        $this->assertSame('m', $tape['unit']);

        // ...and NOT filed. This one boolean is the whole fix: the screen obeys
        // it rather than working the rule out again on its own side.
        $this->assertFalse($tape['submit_as_stock']);

        // The reason says both halves out loud — that the figure is metres,
        // and that it is therefore going nowhere. A withheld number nobody was
        // told about is worse than no number.
        $this->assertStringContainsString('still open', $tape['reason']);
        $this->assertStringContainsString('NOT posted to stock or Tally', $tape['reason']);
        // ...and names the two ways the factory can end it, because a caveat
        // with no exit is just an apology.
        $this->assertStringContainsString('metres per unit', $tape['reason']);
    }

    public function test_the_carton_tray_and_film_lines_are_untouched_by_the_tape_rule(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $lines = collect(
            $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
                ->assertOk()
                ->json('data.suggested_packing'),
        )->keyBy('kind');

        // THE TRAP THIS TEST EXISTS FOR. The tape rule compares a unit against
        // items.uom, and every packing item in this factory's master reads
        // "NOS" — INCLUDING the film, which Tally moves in Kgs. A rule written
        // one degree more general than "tape" would find film's kg against the
        // item's NOS, call it a mismatch, and drop the film line from every
        // completion: the identical defect, new material, and nobody would see
        // it because the row would still be on screen.
        $this->assertTrue($lines['carton']['submit_as_stock']);
        $this->assertTrue($lines['tray']['submit_as_stock']);
        $this->assertTrue($lines['pouch_film']['submit_as_stock']);

        // Their units and factors are exactly what they were — the fix adds a
        // field, it does not renegotiate the arithmetic.
        $this->assertSame(['1', 'nos'], [$lines['carton']['factor'], $lines['carton']['unit']]);
        $this->assertSame(['1', 'nos'], [$lines['tray']['factor'], $lines['tray']['unit']]);
        $this->assertSame('kg', $lines['pouch_film']['unit']);
    }

    public function test_a_tape_item_the_factory_recounts_in_metres_posts_its_metres_unconverted(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        // One of the two answers, and the one that needs no new column: the
        // factory corrects the tape item's unit in the masters. Now the
        // mapping's metres ARE the Tally quantity and there is nothing to
        // convert — the disagreement is simply over.
        $this->catalogue['Packing Tape - Transparent']->update(['uom' => 'Mtr']);

        $tape = $this->previewLine('tape');

        $this->assertTrue($tape['submit_as_stock']);
        // Unconverted, deliberately: 2.2900 m per box is what posts.
        $this->assertSame(self::TAPE_170_ROUND, $tape['factor']);
        $this->assertSame('m', $tape['unit']);
        $this->assertStringContainsString('counted in Mtr', $tape['reason']);
        $this->assertStringNotContainsString('NOT posted', $tape['reason']);
    }

    /**
     * The suggestion service with `metres_per_unit` answered on the tape row.
     *
     * The answer has no COLUMN yet — the migration ships with the factory's
     * figure, see PackingMaterialMapping's docblock — so the attribute is set
     * in memory and handed to the service through a mapping resolver that
     * returns that row. Everything downstream of resolve() is the real code
     * path, which is the half worth proving: the day the column lands, the
     * arithmetic and the flag are already right and already tested.
     */
    private function suggestionsWithTapeAnswer(ProductionStandard $standard, ?string $metresPerUnit): array
    {
        $tape = PackingMaterialMapping::query()
            ->with('item')
            ->where('spec_kind', PackingMaterialMapping::KIND_TAPE)
            ->where('spec_value', '170ML')
            ->firstOrFail();

        $tape->setAttribute('metres_per_unit', $metresPerUnit);

        $mappings = new class(app(PackingItemMatcher::class), $tape) extends PackingMaterialMappingService
        {
            public function __construct(PackingItemMatcher $matcher, private readonly PackingMaterialMapping $tape)
            {
                parent::__construct($matcher);
            }

            public function resolve(string $kind, ?string $spec): ?PackingMaterialMapping
            {
                // Only tape is substituted; the carton, tray and film rows
                // resolve out of the database exactly as they do in
                // production, so this test can still see them come back
                // unchanged.
                return $kind === PackingMaterialMapping::KIND_TAPE
                    ? $this->tape
                    : parent::resolve($kind, $spec);
            }
        };

        return collect((new PackingMaterialSuggestionService($mappings))->forStandard($standard))
            ->keyBy('kind')
            ->all();
    }

    public function test_a_metres_per_unit_answer_converts_the_tape_factor_to_nos_and_lets_it_post(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();
        $standard = ProductionStandard::query()->where('item_id', $this->bottle->id)->firstOrFail();

        // The other answer: 65 m rolls. 2.2900 m of tape per box ÷ 65 m in a
        // roll = 0.03523076 rolls per box, so the 100-carton run in the
        // owner's own example is 100 × 0.03523076 = 3.523076 Nos — which the
        // completion screen shows and submits at its usual 4dp, 3.5231 Nos.
        //
        // THE FACTOR CARRIES EIGHT PLACES, NOT FOUR, and that is the number
        // worth checking here. At 4dp the factor would be 0.0352 and the same
        // 100 cartons would come to 3.52 — a factor's rounding is multiplied
        // by every carton in the shift, so it is kept fine and the PRODUCT is
        // rounded. Nothing rounds to whole rolls: tape is genuinely consumed
        // part way, and calling 3.5231 "4 rolls" would issue half a roll that
        // never left the store.
        $lines = $this->suggestionsWithTapeAnswer($standard, '65');

        $this->assertSame('0.03523076', $lines['tape']['factor']);
        $this->assertTrue($lines['tape']['submit_as_stock']);
        // The unit flips WITH the factor — the pair is the whole point. A
        // per-No factor still labelled "m" is the same class of lie as metres
        // labelled Nos, pointing the other way.
        $this->assertSame('nos', $lines['tape']['unit']);
        $this->assertSame('nos', $lines['tape']['factor_unit']);
        // Still counted per carton: what changed is the unit, not the basis.
        $this->assertSame('cartons', $lines['tape']['quantity_basis']);

        // The division is SHOWN. A bare 0.03523076 beside a carton count means
        // nothing to the floor without the two figures that produced it.
        $this->assertStringContainsString('2.2900 ÷ 65.0000 = 0.03523076', $lines['tape']['reason']);
        $this->assertStringNotContainsString('NOT posted', $lines['tape']['reason']);

        // 100 cartons × the factor = the owner's own worked example. Asserted
        // at full scale rather than rounded here, because bcmath TRUNCATES and
        // the drawer's round4() does not — 3.523076 reaches the payload as
        // 3.5231 Nos, and rounding it with bcadd in this test would quietly
        // assert 3.5230 and pin the wrong number.
        $this->assertSame('3.52307600', bcmul('100', $lines['tape']['factor'], 8));

        // And the other three are still exactly as they were.
        $this->assertTrue($lines['carton']['submit_as_stock']);
        $this->assertSame('nos', $lines['carton']['unit']);
        $this->assertTrue($lines['tray']['submit_as_stock']);
        $this->assertTrue($lines['pouch_film']['submit_as_stock']);
    }

    public function test_a_metres_per_unit_of_zero_is_not_an_answer_and_leaves_the_tape_withheld(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();
        $standard = ProductionStandard::query()->where('item_id', $this->bottle->id)->firstOrFail();

        // A zero here is a DIVISOR, and the hazard is not somebody typing a
        // silly number — it is the division. The guard lives on the model
        // rather than only in the write path so that a row predating the
        // validation rule, or written by a seed, cannot reach it either. Zero
        // means "not answered", which is the safe branch by construction.
        foreach (['0', '0.0000', '-65'] as $notAnAnswer) {
            $lines = $this->suggestionsWithTapeAnswer($standard, $notAnAnswer);

            $this->assertFalse($lines['tape']['submit_as_stock'], "metres_per_unit {$notAnAnswer} must not count as an answer");
            $this->assertSame(self::TAPE_170_ROUND, $lines['tape']['factor']);
            $this->assertSame('m', $lines['tape']['unit']);
        }
    }

    // --------------------------------- (4) the suggestion is POWERLESS ------

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);

        // Enough of everything in the store for the issue lines to post —
        // packing consumables included. Without these the completion fails on
        // a shortage before the voucher is ever reached.
        foreach ([
            $this->resin->id, $this->catalogue['170 Ml Master Box']->id,
            $this->catalogue['60 Ml Tray']->id, $this->catalogue['Packing Tape - Transparent']->id,
            $this->catalogue['LDPE  COVER (28.5x38x120G)']->id,
        ] as $itemId) {
            StockBalance::create([
                'item_id' => $itemId, 'warehouse_id' => $this->rmStore->id,
                'quantity' => '5000.0000', 'average_cost' => '10.0000',
            ]);
        }

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-01',
            'batch_number' => '20260801-MC01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    /**
     * A stored/serialised quantity as a 4dp string, whatever the driver
     * handed back — SQLite gives '97', MySQL '97.0000'. Asserting the raw
     * string would pin the test to the driver rather than to the quantity.
     */
    private function qty(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    public function test_the_tally_voucher_carries_the_submitted_packing_lines_not_the_suggestions(): void
    {
        // THE MONEY LINE. Tally deducts stock and values it from these
        // quantities. If a packing suggestion could ever reach the voucher,
        // the factory's books would be reduced by cartons nobody counted.
        $approver = $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $entry = $this->inProgressEntry();

        // 100 cartons packed. Every submitted figure DELIBERATELY contradicts
        // what the suggestion implies — submitting the suggestion back would
        // pass this test vacuously and keep passing if somebody later added a
        // server-side prefill:
        //
        //   cartons: suggestion 100 x 1 = 100 boxes, supervisor counted 97
        //            (three boxes were short-packed).
        //   trays:   suggestion 500 x 1 = 500 trays, supervisor counted 480.
        //   tape:    suggestion 100 x 2.290 m = 229 m, supervisor issued
        //            3 rolls — which is also the open metres-vs-Nos question
        //            the mapping row states, and the server must not resolve
        //            it either.
        //   film:    no mapping exists for "750*610" at all, and the
        //            supervisor issued 12 kg anyway. An absent suggestion may
        //            not become an absent line.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '20000',
            'running_hours' => 8,
            'no_of_box' => 100,
            'no_of_trays' => 500,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '184.0000'],
                ['item_id' => $this->catalogue['170 Ml Master Box']->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '97.0000'],
                ['item_id' => $this->catalogue['60 Ml Tray']->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '480.0000'],
                ['item_id' => $this->catalogue['Packing Tape - Transparent']->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '3.0000'],
                ['item_id' => $this->catalogue['LDPE  COVER (28.5x38x120G)']->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '12.0000'],
            ],
        ])->assertOk();

        // Five lines in, five lines stored — nothing added from a mapping,
        // nothing recalculated.
        $this->assertSame(5, $entry->fresh()->materialConsumptions->count());

        $service = app(ShiftProductionEntryService::class);
        // Four-eyes: the accountant gate refuses the PM's own account.
        $accountant = User::factory()->create();
        $service->pmApprove($entry->fresh(), $approver->id);
        $service->accountantApprove($entry->fresh(), $accountant->id);

        $voucher = TallySyncEntry::query()->sole();

        // The whole consumed side is the submitted set, in the submitted
        // quantities. Not 100 cartons, not 500 trays, not 229 m of tape.
        $this->assertSame([
            'PET Polyster Chips' => '184.0000',
            '170 Ml Master Box' => '97.0000',
            '60 Ml Tray' => '480.0000',
            'Packing Tape - Transparent' => '3.0000',
            'LDPE  COVER (28.5x38x120G)' => '12.0000',
        ], collect($voucher->payload['consumed'])
            ->mapWithKeys(fn (array $line) => [$line['item'] => $this->qty($line['quantity'])])
            ->all());

        $this->assertNotSame('100.0000', $this->qty(
            collect($voucher->payload['consumed'])->firstWhere('item', '170 Ml Master Box')['quantity'],
        ));
    }

    public function test_completing_with_no_packing_lines_stores_no_packing_material_at_all(): void
    {
        $this->actingAsProduction();
        $this->seedFixtureStandards();
        app(PackingMaterialMappingService::class)->seedFromCatalogue();

        $entry = $this->inProgressEntry();

        // Mappings exist for the carton, the tray and the tape, and the
        // supervisor submitted resin only. The server must NOT helpfully add
        // the packaging: unweighed, uncounted cartons would be a recipe posing
        // as a measurement, and they would reduce Tally stock for material
        // nobody confirmed was used.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '20000',
            'running_hours' => 8,
            'no_of_box' => 100,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '184.0000'],
            ],
        ])->assertOk();

        $consumptions = $entry->fresh()->materialConsumptions;
        $this->assertSame(1, $consumptions->count());
        $this->assertNull($consumptions->firstWhere('item_id', $this->catalogue['170 Ml Master Box']->id));
        $this->assertSame(0, TallySyncEntry::query()->count());
    }
}
