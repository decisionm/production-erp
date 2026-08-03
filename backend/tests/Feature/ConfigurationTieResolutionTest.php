<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The defect this pins: two approved configurations for the same product on
 * the same machine, tied on mould, colour and effective date, and the resolver
 * picking whichever the storage engine returned first.
 *
 * That is not hypothetical. Configurations #62 (4 cavities) and #63 (5) were
 * both approved on MC-04 at the same instant with no effective dates. Every
 * run of that product silently took 4 against a Product Standard of 5 — and
 * the choice would have flipped on an index rebuild, because nothing in the
 * query expressed a preference.
 *
 * No existing test could have caught it: every one of them creates a single
 * configuration, and a test with one candidate cannot discover what happens
 * with two.
 */
class ConfigurationTieResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Item, 1: WorkCenter} */
    private function subject(): array
    {
        $item = Item::create(['sku' => 'BTL-60', 'name' => '60 Ml Round Amber', 'uom' => 'Nos.']);
        $machine = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'display_sequence' => 4]);

        return [$item, $machine];
    }

    private function configuration(Item $item, WorkCenter $machine, int $cavities, ?string $approvedAt, ?int $approvedBy = null): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'colour' => 'Amber',
            'default_cavities' => $cavities,
            'default_cycle_time' => '11.50',
            'unit_weight_grams' => '10.0000',
            'status' => ConfigurationStatus::Approved->value,
            'approved_at' => $approvedAt,
            'approved_by' => $approvedBy,
        ]);
    }

    public function test_the_newest_approved_revision_wins_when_everything_else_ties(): void
    {
        [$item, $machine] = $this->subject();

        // Deliberately inserted oldest-first so a naive "first row" would
        // return the four-cavity one, exactly as production did.
        $older = $this->configuration($item, $machine, 4, '2026-07-31 02:27:25');
        $newer = $this->configuration($item, $machine, 5, '2026-07-31 06:00:00');

        $resolved = (new ProductionConfigurationService)->resolve($machine->id, $item->id);

        $this->assertNotNull($resolved);
        $this->assertSame($newer->id, $resolved->id);
        $this->assertSame(5, $resolved->default_cavities);
        $this->assertNotSame($older->id, $resolved->id);
    }

    public function test_resolution_is_stable_when_even_the_approval_instant_ties(): void
    {
        // The real #62/#63 shape: same approved_at to the second. The id is the
        // last tiebreaker, so the ordering is total and cannot depend on the
        // database — and asking twice must give the same answer.
        [$item, $machine] = $this->subject();

        $this->configuration($item, $machine, 4, '2026-07-31 02:27:25');
        $higher = $this->configuration($item, $machine, 5, '2026-07-31 02:27:25');

        $service = new ProductionConfigurationService;

        $first = $service->resolve($machine->id, $item->id);
        $second = $service->resolve($machine->id, $item->id);

        $this->assertSame($higher->id, $first->id);
        $this->assertSame($first->id, $second->id, 'Resolution must not vary between identical calls.');
    }

    public function test_a_dated_configuration_still_beats_an_undated_one(): void
    {
        // The MC-10 pairs (#85/#87 dated, #84/#86 not) rely on this ordering
        // being unchanged by the new tiebreakers.
        [$item, $machine] = $this->subject();

        $undated = $this->configuration($item, $machine, 3, '2026-07-31 02:27:25');
        $dated = $this->configuration($item, $machine, 7, '2026-07-30 18:22:52');
        $dated->update(['effective_from' => '2026-07-30']);

        $resolved = (new ProductionConfigurationService)->resolve($machine->id, $item->id);

        $this->assertSame($dated->id, $resolved->id);
        $this->assertNotSame($undated->id, $resolved->id);
    }
}
