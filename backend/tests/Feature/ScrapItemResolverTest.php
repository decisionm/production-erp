<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ScrapItemResolver;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Services\PackingVoucherLines;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONE SCRAP-ITEM RESOLVER, AND A MISS THAT SAYS WHICH MISS IT IS.
 *
 * The quality gate's scrap receipt and the Tally voucher's scrap line used to
 * each carry their own copy of "config → exact SKU → exact name → null"
 * (audit §4.15). Both now ask ScrapItemResolver, and null is no longer one
 * answer for two situations: NOT_NAMED (the setting is blank — the factory's
 * choice) is told apart from NAMED_BUT_NOT_FOUND (the setting names
 * something no stock item matches — a misconfiguration that used to read
 * exactly like the choice). Nothing new posts on a miss; only the words
 * changed.
 */
class ScrapItemResolverTest extends TestCase
{
    use RefreshDatabase;

    private ScrapItemResolver $resolver;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Warehouse $fgStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(ScrapItemResolver::class);
    }

    // ------------------------------------------------------- the resolver ---

    public function test_a_blank_setting_is_not_named(): void
    {
        foreach ([null, '', '   '] as $blank) {
            config(['production.scrap.rejected_item_sku' => $blank]);

            $this->assertNull($this->resolver->resolve());
            $this->assertNull($this->resolver->configuredName());
            $this->assertSame(ScrapItemResolver::NOT_NAMED, $this->resolver->missReason());
        }
    }

    public function test_a_setting_matching_nothing_is_named_but_not_found(): void
    {
        // The typo case: the four scrap items exist, none spelled like this.
        Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        Item::create(['sku' => 'PET-SCRAP-AMB', 'name' => 'PET Scrap - Amber', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'Pet Scarp']);

        $this->assertNull($this->resolver->resolve());
        $this->assertSame('Pet Scarp', $this->resolver->configuredName());
        $this->assertSame(ScrapItemResolver::NAMED_BUT_NOT_FOUND, $this->resolver->missReason());
    }

    public function test_it_never_pattern_matches(): void
    {
        // "Pet Scrap" is a prefix of every other scrap name in the factory's
        // masters; a LIKE would pick one. Exact or nothing.
        Item::create(['sku' => 'PET-SCRAP-AMB', 'name' => 'PET Scrap - Amber', 'uom' => 'Kgs.']);
        Item::create(['sku' => 'PET-SCRAP-LMP', 'name' => 'PET Scrap - Lumps', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'PET Scrap']);

        $this->assertNull($this->resolver->resolve());
        $this->assertSame(ScrapItemResolver::NAMED_BUT_NOT_FOUND, $this->resolver->missReason());
    }

    public function test_it_resolves_by_exact_sku_first(): void
    {
        $bySku = Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        // A decoy whose NAME is the configured string — SKU wins.
        Item::create(['sku' => 'OTHER', 'name' => 'PET-SCRAP', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);

        $this->assertSame($bySku->id, $this->resolver->resolve()?->id);
        $this->assertNull($this->resolver->missReason());
    }

    public function test_it_resolves_by_exact_name_when_no_sku_matches(): void
    {
        // A factory that mirrors Tally's "Pet Scrap" as a plain item without a
        // code can still name it — the setting's packaged default.
        $byName = Item::create(['sku' => 'SCR-0001', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'Pet Scrap']);

        $this->assertSame($byName->id, $this->resolver->resolve()?->id);
        $this->assertNull($this->resolver->missReason());
    }

    public function test_a_retired_scrap_item_is_named_but_not_found(): void
    {
        // A soft-deleted master must not silently start receiving stock again
        // — and the miss says the setting names something, so the accountant
        // looks at the master rather than concluding nobody named one.
        $scrap = Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);
        $scrap->delete();

        $this->assertNull($this->resolver->resolve());
        $this->assertSame(ScrapItemResolver::NAMED_BUT_NOT_FOUND, $this->resolver->missReason());
    }

    // ------------------------------------------- both services say the same ---

    /** The floor the two services share: one product, one shift, one store. */
    private function factory(): void
    {
        config(['production.approvals.quality_stage_enabled' => true]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->bottle = Item::create([
            'sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS',
            'is_active' => true, 'nominal_weight_grams' => '12.9000',
        ]);
    }

    /** A completed, unchecked batch that scrapped something. */
    private function completedEntry(): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-M01-001',
            'batch_status' => BatchStatus::Completed,
            'status' => ShiftProductionEntryStatus::Pending,
            'quantity_produced' => '10000',
            'quantity_scrap' => '120',
        ]);
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '4.5000']);

        return $entry->fresh();
    }

    /** The QC desk rejects 200 pieces; returns the note the entry records. */
    private function qualityScrapNote(ShiftProductionEntry $entry): ?string
    {
        app(ShiftProductionEntryService::class)->recordQualityCheck(
            $entry,
            ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200],
            User::factory()->create()->id,
        );

        return $entry->fresh()->quality_scrap_note;
    }

    /** The batch voucher's withheld scrap reason, or null when scrap posted. */
    private function withheldScrapReason(ShiftProductionEntry $entry): ?string
    {
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry->fresh());

        foreach ($payload['withheld'] as $withheld) {
            if ($withheld['kind'] === PackingVoucherLines::WITHHELD_SCRAP) {
                return $withheld['reason'];
            }
        }

        return null;
    }

    public function test_both_services_say_not_named_when_the_setting_is_blank(): void
    {
        $this->factory();
        config(['production.scrap.rejected_item_sku' => null]);
        $entry = $this->completedEntry();

        $reason = $this->withheldScrapReason($entry);
        $this->assertNotNull($reason);
        // The LEAD states the owner's ruling — not naming a scrap item is the
        // factory's standing choice — and the note names which silence it is.
        $this->assertStringContainsString('discards rejects and lumps (owner ruling, 05-Aug)', $reason);
        $this->assertStringNotContainsString('could not be found', $reason);
        $this->assertStringContainsString('not named in configuration', $reason);
        $this->assertStringContainsString('production.scrap.rejected_item_sku', $reason);
        $this->assertStringNotContainsString('matches no stock item', $reason);

        $note = $this->qualityScrapNote($entry);
        $this->assertNotNull($note);
        $this->assertStringContainsString('no scrap item is named in configuration', $note);
        $this->assertStringContainsString('production.scrap.rejected_item_sku', $note);
        $this->assertStringNotContainsString('matches no stock item', $note);
    }

    public function test_both_services_name_the_misconfiguration_when_the_setting_matches_nothing(): void
    {
        $this->factory();
        // The item the factory's books use, spelled wrong in the setting.
        Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'Pet Scarp']);
        $entry = $this->completedEntry();

        $reason = $this->withheldScrapReason($entry);
        $this->assertNotNull($reason);
        // The LEAD says the item could not be found and does NOT cite the
        // owner's ruling — a typo is a misconfiguration, not a decision, and
        // a reason that opened with the ruling and closed with "this is a
        // misconfiguration" contradicted itself.
        $this->assertStringContainsString('scrap item named in configuration could not be found', $reason);
        $this->assertStringNotContainsString('owner ruling', $reason);
        $this->assertStringNotContainsString('discards rejects and lumps', $reason);
        $this->assertStringContainsString("configured 'Pet Scarp' matches no stock item", $reason);
        $this->assertStringContainsString('production.scrap.rejected_item_sku', $reason);
        $this->assertStringContainsString('this is a misconfiguration, not a decision', $reason);
        $this->assertStringNotContainsString('not named in configuration', $reason);

        $note = $this->qualityScrapNote($entry);
        $this->assertNotNull($note);
        $this->assertStringContainsString("configured scrap item 'Pet Scarp' matches no stock item", $note);
        $this->assertStringContainsString('production.scrap.rejected_item_sku', $note);
        $this->assertStringNotContainsString('no scrap item is named', $note);

        // A miss still withholds — nothing was booked against a guess.
        $this->assertDatabaseMissing('stock_movements', ['reference' => "QC #{$entry->id}", 'type' => 'receipt']);
    }

    public function test_both_services_use_the_same_item_once_it_resolves(): void
    {
        $this->factory();
        $scrap = Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'Pet Scrap']);
        $entry = $this->completedEntry();

        // No withheld line: the voucher carries the scrap as a produced line
        // under the resolved name.
        $this->assertNull($this->withheldScrapReason($entry));
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry->fresh());
        $this->assertContains('Pet Scrap', collect($payload['produced'])->pluck('item')->all());

        // And the quality gate receives the rejection onto THAT item.
        $this->assertNull($this->qualityScrapNote($entry));
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $scrap->id, 'reference' => "QC #{$entry->id}", 'type' => 'receipt',
        ]);
    }
}
