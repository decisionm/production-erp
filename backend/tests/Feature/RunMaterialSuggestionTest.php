<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PRE-SELECTED MATERIALS — which resin, which masterbatch, and the grams per
 * bottle for each, so the completion screen never leaves a dropdown for the
 * supervisor to guess at.
 *
 * The owner has asked three times for the same layout: the material box
 * pre-filled, grams per bottle pre-filled and editable, the total kg beside
 * it. Their latest screenshot shows the three defects this suite pins:
 *
 *   (a) the Resin box arriving EMPTY,
 *   (b) a WHITE masterbatch pre-selected on a run that is not white — chosen
 *       by first name match with no tier able to say "I don't know",
 *   (c) the "From" box surviving on the masterbatch row (frontend; not this
 *       suite's business).
 *
 * Two things are proved here and must never be confused:
 *
 *   1. the suggestion is RIGHT — history beats spelling, the colour resolves
 *      on master data, and every doubtful case resolves to null with a
 *      sentence rather than to another colour's material; and
 *   2. the suggestion is POWERLESS — completing stores exactly the lines
 *      submitted, and the Tally voucher carries those. That second half is
 *      the money line: it is what stops a pre-fill from quietly becoming a
 *      measurement.
 */
class RunMaterialSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /** The factory's figure (owner, 31-Jul): amber masterbatch, 0.25 g/bottle. */
    private const AMBER_GRAMS_PER_BOTTLE = '0.25';

    private Warehouse $rmStore;

    private Warehouse $fg;

    private Item $amberBottle;

    private Item $chips;

    private Item $amberMb;

    private Item $whiteMb;

    protected function setUp(): void
    {
        parent::setUp();

        // Run material suggestions are what this suite pins; approvals here are
        // scaffolding to reach the posted figures. The quality gate is covered
        // in BatchQualityStageTest.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);

        // The factory's real item names and units: raw material in Kgs,
        // bottles in Nos. A 5 g bottle, so its resin grams per bottle is 5.
        $this->amberBottle = Item::create([
            'sku' => 'BTL-AMBER-1', 'name' => 'A.15ml Round Pet Bottle Amber-5gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '5.0000', 'colour' => 'Amber',
        ]);
        $this->chips = Item::create(['sku' => 'PET-CHIPS', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs']);

        // The masterbatches, carrying the colour DeriveItemColours reads out
        // of a Tally name. Amber and White only — "Masterbatch -Red(Brown)"
        // can never get one (the command's vocabulary has no Red or Brown),
        // which is exactly the gap the factory map exists to close.
        $this->amberMb = Item::create(['sku' => 'MB-AMBER', 'name' => 'Master Batch Amber', 'uom' => 'Kgs', 'colour' => 'Amber']);
        $this->whiteMb = Item::create(['sku' => 'MB-WHITE', 'name' => 'ARIHANT PET WHITE 1020 Master Batch', 'uom' => 'Kgs', 'colour' => 'White']);
    }

    private function actingAsProduction(string $permission = 'production.manage'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    /** The amber dosing in force, entered the way a person would: through the API. */
    private function setAmberDosing(string $grams = self::AMBER_GRAMS_PER_BOTTLE): void
    {
        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->amberMb->id,
            'grams_per_bottle' => $grams,
            'note' => 'Factory, 31 Jul — owner on WhatsApp.',
        ])->assertOk();
    }

    /** The colour → masterbatch map, written through the existing settings endpoint. */
    private function mapColour(array $map): void
    {
        $this->postJson('/api/v1/production/factory-settings', [
            'key' => RunMaterialSuggestionService::COLOUR_MAP_KEY,
            'value' => json_encode($map),
            'data_type' => 'json',
            'label' => 'Masterbatch for each colour',
            'change_reason' => 'Factory stated which masterbatch each colour uses.',
        ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(?Item $item = null, array $extra = []): array
    {
        $query = http_build_query(['item_id' => ($item ?? $this->amberBottle)->id, ...$extra]);

        return $this->getJson("/api/v1/production/shift-production-entries/preview?{$query}")
            ->assertOk()
            ->json('data');
    }

    private function shift(): Shift
    {
        return Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
    }

    private function machine(): WorkCenter
    {
        return WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1']);
    }

    private function entry(BatchStatus $status = BatchStatus::Completed, ?Item $item = null): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift()->id,
            'work_center_id' => $this->machine()->id,
            'item_id' => ($item ?? $this->amberBottle)->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-MC01-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'batch_status' => $status,
            'quantity_produced' => $status === BatchStatus::InProgress ? null : '13333',
            'quantity_scrap' => '0',
        ]);
    }

    /**
     * A past shift that issued these materials. This is the factory STATING,
     * with a weight, that the material went into bottles — the record the
     * resin suggestion is resolved from.
     *
     * @param  array<int, string>  $issued  item id => kg
     */
    private function issuedOnAPastShift(array $issued): void
    {
        $entry = $this->entry();

        foreach ($issued as $itemId => $kg) {
            ShiftMaterialConsumption::create([
                'shift_production_entry_id' => $entry->id,
                'item_id' => $itemId,
                'warehouse_id' => $this->rmStore->id,
                'quantity_issued_kg' => $kg,
            ]);
        }
    }

    /** A stored kg as a 4dp string, whatever the driver handed back (SQLite '4' vs MySQL '4.0000'). */
    private function kg(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    // ----------------------------------------------------------------- resin --

    public function test_the_resin_item_comes_from_what_was_issued_and_beats_the_name_pattern(): void
    {
        $this->actingAsProduction();

        // A grade whose NAME says nothing — no "resin", no "chips", no
        // "polyster". The only thing that knows it is the resin is the
        // factory's own issue history.
        $sanpet = Item::create(['sku' => 'SANPET-80', 'name' => 'Sanpet Bright 80 Grade', 'uom' => 'Kgs']);

        // Two shifts of it, against a single shift of the pattern-matching
        // "PET Polyster Chips" — so a name rule and the history disagree, and
        // the test can only pass one way.
        $this->issuedOnAPastShift([$sanpet->id => '180.0000']);
        $this->issuedOnAPastShift([$sanpet->id => '176.5000']);
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);

        $resin = $this->preview()['suggested_resin'];

        $this->assertSame($sanpet->id, $resin['item']['id']);
        $this->assertSame('consumption_history', $resin['source']);
        // The sentence the screen prints, so a supervisor accepting a
        // pre-filled material knows where it came from.
        $this->assertStringContainsString('Sanpet Bright 80 Grade', $resin['reason']);
        $this->assertStringContainsString('issued', $resin['reason']);

        // Wipe the history and the name pattern is all that is left: the
        // spelling-matched item, labelled as such. This is the day-one answer
        // on a fresh instance, never the preferred one.
        ShiftMaterialConsumption::query()->delete();

        $fallback = $this->preview()['suggested_resin'];
        $this->assertSame($this->chips->id, $fallback['item']['id']);
        $this->assertSame('name_pattern', $fallback['source']);
        $this->assertStringContainsString('item name', $fallback['reason']);
    }

    public function test_a_unit_of_measure_gap_still_leaves_the_resin_box_filled(): void
    {
        // Defect (a) insurance. "Raw material" is read off the item's unit,
        // and this factory's Tally masters spell that unit four ways — a
        // spelling nobody mapped ('NOS' on a resin, as the local catalogue
        // copy actually shows) empties the kg pool and would hand the
        // supervisor back the empty Resin dropdown they have complained about
        // three times. The name pattern is retried without the unit gate, and
        // the reason says which master to fix.
        $this->actingAsProduction();
        $this->chips->update(['uom' => 'NOS']);
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);

        $resin = $this->preview()['suggested_resin'];

        $this->assertSame($this->chips->id, $resin['item']['id']);
        $this->assertSame('name_pattern_unit_gap', $resin['source']);
        $this->assertStringContainsString('unit of measure', $resin['reason']);
    }

    public function test_the_resin_grams_per_bottle_is_the_runs_own_resolved_unit_weight(): void
    {
        $this->actingAsProduction();
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);

        // Nothing but the item master: a 5 g bottle consumes 5 g of resin.
        $this->assertSame('5.0000', $this->preview()['suggested_resin']['grams_per_bottle']);

        // The factory product standard is more specific than the item master.
        ProductionStandard::create([
            'item_id' => $this->amberBottle->id,
            'source_product_name' => 'A.15ml Round Pet Bottle Amber-5gms',
            'cavities' => 4, 'unit_weight_grams' => '5.2000', 'cycle_time' => '12.00',
            'status' => 'approved',
        ]);
        $this->assertSame('5.2000', $this->preview()['suggested_resin']['grams_per_bottle']);

        // ...and the approved machine configuration beats the standard, which
        // is the same precedence the batch's own snapshot uses. A preview
        // quoting a weight the run is not measured against would put the
        // screen at odds with the gate.
        ProductionConfiguration::create([
            'work_center_id' => $this->machine()->id,
            'item_id' => $this->amberBottle->id,
            'default_cycle_time' => '12.50', 'default_cavities' => 4,
            'unit_weight_grams' => '5.4000',
            'status' => ConfigurationStatus::Approved->value,
            'source' => 'DAILY-PRODUCTION-REVIEW',
        ]);

        $withMachine = $this->preview(extra: ['work_center_id' => $this->machine()->id]);
        $this->assertSame('5.4000', $withMachine['suggested_resin']['grams_per_bottle']);
    }

    public function test_the_resin_block_carries_no_total_kg_of_its_own(): void
    {
        // THE OWNER'S OWN ARITHMETIC. Their screenshot shows 5.0 g/bottle and
        // 71.61 kg against 13,333 bottles — but 13,333 × 5 g is 66.665 kg.
        // The missing ~4.95 kg is the rejection and lumps mass: the resin
        // total is the factory's paper formula (production + rejection +
        // lumps), verified 11 rows of 11, NOT grams × bottles.
        //
        // So this block states the basis and offers no kg of its own. A
        // suggested_kg here would invite the screen to replace a verified
        // figure with one that is light by the scrap mass every shift, in the
        // same direction — a permanent phantom shortage in the variance the
        // accountant is asked to explain.
        $this->actingAsProduction();
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);

        $resin = $this->preview(extra: ['quantity_produced' => 13333])['suggested_resin'];

        $this->assertArrayNotHasKey('suggested_kg', $resin);
        $this->assertSame('production_kg + rejection_kg + lumps_kg', $resin['total_kg_basis']);
        // The bottle count IS carried, so the screen can show its own
        // arithmetic against the same basis as the masterbatch row.
        $this->assertSame(13333, $resin['bottles']);
    }

    public function test_the_resin_and_the_masterbatch_are_never_the_same_material(): void
    {
        // Resin and masterbatch are issued on the SAME shifts, so ranking by
        // how often a material was issued ties between them constantly. The
        // kg tiebreak is what keeps them apart (172 kg against 4 kg is not a
        // close call) — and a colourant is held out of the resin candidates
        // in the first place.
        $this->actingAsProduction();
        $this->setAmberDosing();

        foreach (range(1, 3) as $ignored) {
            $this->issuedOnAPastShift([$this->chips->id => '172.0000', $this->amberMb->id => '4.0000']);
        }

        $preview = $this->preview();

        $this->assertSame($this->chips->id, $preview['suggested_resin']['item']['id']);
        $this->assertSame($this->amberMb->id, $preview['suggested_masterbatch']['item']['id']);
        $this->assertNotSame(
            $preview['suggested_resin']['item']['id'],
            $preview['suggested_masterbatch']['item']['id'],
            'The resin and the masterbatch must never resolve to one material',
        );
    }

    public function test_two_materials_the_issue_history_cannot_separate_resolve_to_null(): void
    {
        // The history is the authority for the resin — so when the history
        // itself holds two identical answers it must say so, not break the tie
        // on item id. A coin toss dressed as a ranking is the same defect as
        // the wrong masterbatch: a confident pre-fill nobody can audit.
        $this->actingAsProduction();

        $relpet = Item::create(['sku' => 'RELPET', 'name' => 'Relpet', 'uom' => 'Kgs']);

        // Issued the same number of times, for the same weight, on the same
        // shift — a dead heat on every ranking key (entries, recency, kg).
        $this->issuedOnAPastShift([$this->chips->id => '172.0000', $relpet->id => '172.0000']);

        $resin = $this->preview()['suggested_resin'];

        $this->assertNull($resin['item']);
        $this->assertSame('ambiguous_history', $resin['source']);
        // Both names are printed, so the supervisor is choosing between two
        // stated candidates rather than reopening an empty dropdown.
        $this->assertStringContainsString('PET Polyster Chips', $resin['reason']);
        $this->assertStringContainsString('Relpet', $resin['reason']);

        // One more kg on either side separates them and the suggestion returns.
        $this->issuedOnAPastShift([$relpet->id => '172.0000']);
        $this->assertSame($relpet->id, $this->preview()['suggested_resin']['item']['id']);
    }

    public function test_two_resin_spellings_and_no_history_at_all_resolve_to_null(): void
    {
        // Day one on a fresh instance: nothing has been issued, so only the
        // spelling is left — and this catalogue holds TWO resin spellings
        // ("PET Polyster Chips" and "Relpet"), which the pattern alone cannot
        // choose between. Null and a sentence, never the lower item id.
        $this->actingAsProduction();

        Item::create(['sku' => 'RELPET', 'name' => 'Relpet', 'uom' => 'Kgs']);

        $resin = $this->preview()['suggested_resin'];

        $this->assertNull($resin['item']);
        $this->assertSame('ambiguous_name', $resin['source']);
        $this->assertStringContainsString('PET Polyster Chips', $resin['reason']);
        $this->assertStringContainsString('Relpet', $resin['reason']);

        // The grams per bottle is NOT withheld with the item: the bottle still
        // weighs 5 g whichever resin fills it, and the supervisor picking the
        // material should not have to re-derive the figure beside it.
        $this->assertSame('5.0000', $resin['grams_per_bottle']);
    }

    // ----------------------------------------------------------- masterbatch --

    public function test_the_supervisors_own_colour_outranks_the_masters(): void
    {
        // Colour picks the masterbatch, and most bottle items carry none — so
        // Start Batch ASKS, and that typed answer is frozen into the entry.
        // The preview has to honour the same answer, or the completion drawer
        // would offer a material for a different colour than the run is
        // recorded as making.
        $this->actingAsProduction();
        $this->setAmberDosing();

        // An item master that says White, on a run the supervisor says is Amber.
        $mislabelled = Item::create([
            'sku' => 'BTL-WHITE-1', 'name' => 'A.15ml Round Pet Bottle-5gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '5.0000', 'colour' => 'White',
        ]);

        // Left to the masters, this run gets the white masterbatch...
        $fromMasters = $this->preview($mislabelled)['suggested_masterbatch'];
        $this->assertSame($this->whiteMb->id, $fromMasters['item']['id']);

        // ...but the supervisor is looking at the machine, and it is amber.
        $stated = $this->preview($mislabelled, ['colour' => 'Amber', 'quantity_produced' => 13333]);
        $mb = $stated['suggested_masterbatch'];

        $this->assertSame($this->amberMb->id, $mb['item']['id']);
        $this->assertSame('0.2500', $mb['grams_per_bottle']);
        $this->assertSame('3.3333', $mb['suggested_kg']);

        // ...and a supervisor who says Clear is told no masterbatch is needed,
        // even though the item master holds a colour that has one.
        $clear = $this->preview($mislabelled, ['colour' => 'Clear'])['suggested_masterbatch'];
        $this->assertNull($clear['item']);
        $this->assertSame('Clear bottles take no masterbatch.', $clear['reason']);
    }

    public function test_a_clear_bottle_is_told_it_takes_no_masterbatch(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        $clear = Item::create([
            'sku' => 'BTL-CLEAR-1', 'name' => 'A.15ml Round Pet Bottle Clear-5gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '5.0000', 'colour' => 'Clear',
        ]);

        $mb = $this->preview($clear)['suggested_masterbatch'];

        $this->assertNull($mb['item']);
        $this->assertNull($mb['grams_per_bottle']);
        $this->assertNull($mb['suggested_kg']);
        // The screen must be able to SAY this, not infer it from a null — a
        // blank box with no sentence reads as a question still to answer.
        $this->assertSame('clear', $mb['source']);
        $this->assertSame('Clear bottles take no masterbatch.', $mb['reason']);
    }

    public function test_an_amber_bottle_resolves_the_amber_masterbatch_and_its_quarter_gram(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        $mb = $this->preview(extra: ['quantity_produced' => 13333])['suggested_masterbatch'];

        $this->assertSame($this->amberMb->id, $mb['item']['id']);
        $this->assertSame('Master Batch Amber', $mb['item']['name']);
        $this->assertSame('item_colour', $mb['source']);
        $this->assertSame('0.2500', $mb['grams_per_bottle']);
        // 13,333 × 0.25 g = 3.33325 kg → 3.3333 at the 4dp Tally carries.
        // This IS the owner's rule for the colour: grams × bottles ÷ 1000.
        $this->assertSame('3.3333', $mb['suggested_kg']);
        $this->assertSame(13333, $mb['bottles']);
        $this->assertStringContainsString('Master Batch Amber', $mb['reason']);
        $this->assertStringContainsString('0.2500', $mb['reason']);
    }

    public function test_a_colour_with_no_configured_masterbatch_never_borrows_another_colours_material(): void
    {
        // THE OWNER'S BUG. Their screenshot pre-selected "ARIHANT PET WHITE
        // 1020 Master Batch" on a run that was not white, because the choice
        // was made by first name/colour match with no tier able to answer "I
        // don't know". A red bottle here has no masterbatch the masters can
        // name: the amber one has a dosing and the white one exists, and
        // NEITHER may be offered.
        $this->actingAsProduction();
        $this->setAmberDosing();

        $red = Item::create([
            'sku' => 'BTL-RED-1', 'name' => 'B.200 Ml Brute Pet Bottle Red-18gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '18.0000', 'colour' => 'Red',
        ]);

        $mb = $this->preview($red, ['quantity_produced' => 13333])['suggested_masterbatch'];

        $this->assertNull($mb['item']);
        $this->assertNull($mb['grams_per_bottle']);
        $this->assertNull($mb['suggested_kg']);
        $this->assertSame('unmapped', $mb['source']);
        // The two assertions that pin the bug: nothing was offered, and in
        // particular not the amber material whose dosing is the only figure
        // in the master.
        $this->assertNotSame($this->amberMb->id, $mb['item']['id'] ?? null);
        $this->assertNotSame($this->whiteMb->id, $mb['item']['id'] ?? null);
        // ...and the reason says what to do about it.
        $this->assertStringContainsString('Red', $mb['reason']);
        $this->assertStringContainsString('factory settings', $mb['reason']);

        // The resin row is unaffected: a colour nobody has configured must
        // not cost the supervisor the resin pre-fill as well.
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);
        $this->assertSame($this->chips->id, $this->preview($red)['suggested_resin']['item']['id']);
    }

    public function test_two_candidate_masterbatches_for_one_colour_resolve_to_null(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        // A second amber kg material in the masters — a rebranded bag, or an
        // amber scrap the colour command labelled. Whatever it is, the
        // masters now hold two answers and this code does not toss a coin.
        $second = Item::create(['sku' => 'MB-AMBER-2', 'name' => 'Amber Master Batch (Arihant)', 'uom' => 'Kgs', 'colour' => 'Amber']);

        $mb = $this->preview()['suggested_masterbatch'];

        $this->assertNull($mb['item']);
        $this->assertNull($mb['suggested_kg']);
        $this->assertSame('ambiguous_colour', $mb['source']);
        $this->assertStringContainsString('Master Batch Amber', $mb['reason']);
        $this->assertStringContainsString('Amber Master Batch (Arihant)', $mb['reason']);

        // One row of factory-stated DATA settles it permanently — and the
        // dosing follows the chosen material.
        $this->mapColour(['Amber' => $second->id]);

        $mapped = $this->preview(extra: ['quantity_produced' => 13333])['suggested_masterbatch'];
        $this->assertSame($second->id, $mapped['item']['id']);
        $this->assertSame('factory_map', $mapped['source']);
        // No dosing exists for THAT material, so the grams stay blank rather
        // than borrowing amber's 0.25 — null, never a zero.
        $this->assertNull($mapped['grams_per_bottle']);
        $this->assertNull($mapped['suggested_kg']);
        $this->assertStringContainsString('mapped', $mapped['reason']);
    }

    public function test_the_factory_can_map_a_colour_its_item_masters_cannot_answer(): void
    {
        // "Masterbatch -Red(Brown)" names two colours, so DeriveItemColours
        // leaves it alone forever — a name scan is exactly what must not be
        // reached for. The factory says which material red uses, as data,
        // through the endpoint it already has.
        $this->actingAsProduction();

        $redMb = Item::create(['sku' => 'MB-RED', 'name' => 'Masterbatch -Red(Brown)', 'uom' => 'Kgs']);
        $red = Item::create([
            'sku' => 'BTL-RED-2', 'name' => 'B.200 Ml Brute Pet Bottle Red-18gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '18.0000', 'colour' => 'Red',
        ]);

        $this->mapColour(['Amber' => $this->amberMb->id, 'Red' => $redMb->id]);

        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $redMb->id,
            'grams_per_bottle' => '0.4',
            'note' => 'Factory figure for red.',
        ])->assertOk();

        $mb = $this->preview($red, ['quantity_produced' => 10000])['suggested_masterbatch'];

        $this->assertSame($redMb->id, $mb['item']['id']);
        $this->assertSame('factory_map', $mb['source']);
        $this->assertSame('0.4000', $mb['grams_per_bottle']);
        // 10,000 × 0.4 g = 4 kg.
        $this->assertSame('4.0000', $mb['suggested_kg']);

        // A map entry pointing at a Nos-unit item (a bottle, a carton) is
        // ignored rather than trusted: nothing validates the settings row on
        // the way in, so it is validated on the way out.
        $this->mapColour(['Red' => $this->amberBottle->id]);
        $bad = $this->preview($red)['suggested_masterbatch'];
        $this->assertNull($bad['item']);
        $this->assertSame('unmapped', $bad['source']);
    }

    public function test_a_bottle_with_no_colour_says_so_instead_of_guessing(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        $colourless = Item::create([
            'sku' => 'BTL-NOCOL', 'name' => 'C.500 Ml Pet Bottle-22gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '22.0000',
        ]);

        $mb = $this->preview($colourless)['suggested_masterbatch'];

        $this->assertNull($mb['item']);
        $this->assertSame('no_colour', $mb['source']);
        $this->assertStringContainsString('no colour', $mb['reason']);
    }

    public function test_the_run_reports_the_colour_it_was_started_with(): void
    {
        // The last link in the chain. The supervisor's colour is frozen into
        // config_snapshot at Start, and the completion drawer has to be able
        // to read it back to ask the preview for THAT colour's masterbatch.
        // While it was write-only, the drawer could only offer the item
        // master's colour — blank on most bottle items.
        $this->actingAsProduction();

        $colourless = Item::create([
            'sku' => 'BTL-NOCOL-2', 'name' => 'C.500 Ml Pet Bottle-22gms', 'uom' => 'NOS',
            'nominal_weight_grams' => '22.0000',
        ]);

        StockBalance::create(['item_id' => $colourless->id, 'warehouse_id' => $this->fg->id, 'quantity' => '0', 'average_cost' => '0']);

        $started = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift()->id,
            'work_center_id' => $this->machine()->id,
            'item_id' => $colourless->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
            'colour' => 'Amber',
        ])->assertOk()->json('data');

        // Readable back on the entry, so the drawer can forward it...
        $this->assertSame('Amber', $started['colour']);

        // ...and the preview answers for the colour the RUN states, which the
        // item master could not have supplied at all.
        $this->setAmberDosing();
        $mb = $this->preview($colourless, ['colour' => $started['colour']])['suggested_masterbatch'];

        $this->assertSame($this->amberMb->id, $mb['item']['id']);
        $this->assertSame('0.2500', $mb['grams_per_bottle']);

        // Without it, the same run can only be told the colour is unknown.
        $this->assertSame('no_colour', $this->preview($colourless)['suggested_masterbatch']['source']);
    }

    // ------------------------------- the suggestions are POWERLESS ------------

    public function test_completing_stores_exactly_the_submitted_lines_and_tally_carries_them(): void
    {
        // THE MONEY LINE. Both suggestions exist and both are contradicted by
        // what the supervisor weighed. Tally deducts and values stock from the
        // stored figures; if a suggestion could ever reach a voucher, the
        // factory's books would move by a figure nobody weighed.
        $approver = $this->actingAsProduction();
        $this->setAmberDosing();
        $this->issuedOnAPastShift([$this->chips->id => '172.0000']);

        // The suggestion at this point: PET Polyster Chips at 5 g/bottle, and
        // Master Batch Amber at 0.25 g/bottle = 3.3333 kg for 13,333 bottles.
        $preview = $this->preview(extra: ['quantity_produced' => 13333]);
        $this->assertSame($this->chips->id, $preview['suggested_resin']['item']['id']);
        $this->assertSame('3.3333', $preview['suggested_masterbatch']['suggested_kg']);

        StockBalance::create(['item_id' => $this->chips->id, 'warehouse_id' => $this->rmStore->id, 'quantity' => '5000.0000', 'average_cost' => '85.0000']);
        StockBalance::create(['item_id' => $this->amberMb->id, 'warehouse_id' => $this->rmStore->id, 'quantity' => '500.0000', 'average_cost' => '450.0000']);

        $entry = $this->entry(BatchStatus::InProgress);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '13333',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->chips->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '71.6100'],
                ['item_id' => $this->amberMb->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '4.0000'],
            ],
        ])->assertOk();

        $stored = $entry->fresh()->materialConsumptions;
        $this->assertSame(2, $stored->count(), 'Two lines in, two lines stored — nothing invented from a suggestion');
        $this->assertSame('4.0000', $this->kg($stored->firstWhere('item_id', $this->amberMb->id)->quantity_issued_kg));
        $this->assertNotSame('3.3333', $this->kg($stored->firstWhere('item_id', $this->amberMb->id)->quantity_issued_kg));
        $this->assertSame('71.6100', $this->kg($stored->firstWhere('item_id', $this->chips->id)->quantity_issued_kg));

        $service = app(ShiftProductionEntryService::class);
        // Four-eyes: the accountant gate refuses the PM's own account.
        $accountant = User::factory()->create();
        $service->pmApprove($entry->fresh(), $approver->id);
        $service->accountantApprove($entry->fresh(), $accountant->id);

        $voucher = TallySyncEntry::query()->sole();

        // The whole consumed side is the submitted set, in the submitted
        // quantities — nothing added, nothing recalculated from a dosing.
        $this->assertSame(
            ['PET Polyster Chips' => '71.6100', 'Master Batch Amber' => '4.0000'],
            collect($voucher->payload['consumed'])
                ->mapWithKeys(fn (array $line) => [$line['item'] => $this->kg($line['quantity'])])
                ->all(),
        );
    }

    public function test_a_supervisor_who_submits_no_masterbatch_gets_none_stored(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        StockBalance::create(['item_id' => $this->chips->id, 'warehouse_id' => $this->rmStore->id, 'quantity' => '5000.0000', 'average_cost' => '85.0000']);

        $entry = $this->entry(BatchStatus::InProgress);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '13333',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->chips->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '71.6100'],
            ],
        ])->assertOk();

        // A pre-selected material is an offer, not a line. Adding the colour
        // here would reduce Tally stock for material nobody confirmed.
        $consumptions = $entry->fresh()->materialConsumptions;
        $this->assertSame(1, $consumptions->count());
        $this->assertNull($consumptions->firstWhere('item_id', $this->amberMb->id));
    }
}
