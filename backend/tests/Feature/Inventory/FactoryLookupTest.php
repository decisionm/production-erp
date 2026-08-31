<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Services\FactoryLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT IS THIS NUMBER? — the six identifier spaces behind one box.
 *
 * The tests that matter here are not "does it find things". They are the
 * three rules that decide whether a person is SENT somewhere or SHOWN a
 * list, because getting those wrong sends a storekeeper confidently to the
 * wrong record:
 *
 *   · a jump needs an EXACT hit, on a GLOBALLY unique identifier, with
 *     exactly ONE match in the whole result;
 *   · batch and serial numbers are unique only WITHIN an item, so they never
 *     jump however exact the term;
 *   · a supplier's lot number is not ours, is nullable and is not unique, so
 *     it never jumps either.
 */
class FactoryLookupTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->resin = Item::create([
            'sku' => 'FL-RESIN-01', 'name' => 'FL Resin', 'uom' => 'KGS', 'is_active' => true,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);
    }

    /** @return array<string, mixed> */
    private function look(string $term): array
    {
        return $this->getJson('/api/v1/inventory/lookup?q='.urlencode($term))->assertOk()->json('data');
    }

    private function bag(string $barcode): void
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-08-20',
            'bag_count' => 1,
            'bag_weight_kg' => '25.0000',
            'total_received_kg' => '25.0000',
            'supplier_lot_no' => 'SUP-LOT-9',
        ]);

        $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => '25.0000',
            'remaining_kg' => '25.0000',
            'status' => MaterialBagStatus::WaitingQc,
        ]);
    }

    private function storeIssue(string $number): StoreIssue
    {
        return StoreIssue::create([
            'issue_number' => $number,
            'status' => 'issued',
            'issued_by' => User::factory()->create()->id,
            'issued_at' => now(),
        ]);
    }

    /* ---------------------------------------------------------------- *
     * The jump
     * ---------------------------------------------------------------- */

    public function test_an_exact_sku_resolves_to_that_item(): void
    {
        $result = $this->look('FL-RESIN-01');

        $this->assertNotNull($result['resolved'], 'a unique exact identifier must send the reader straight there');
        $this->assertSame('item', $result['resolved']['kind']);
        $this->assertSame($this->resin->id, $result['resolved']['id']);
    }

    public function test_an_exact_bag_barcode_resolves_to_that_bag(): void
    {
        $this->bag('FL-BAG-0001');

        $result = $this->look('FL-BAG-0001');

        $this->assertNotNull($result['resolved']);
        $this->assertSame('bag', $result['resolved']['kind']);
    }

    public function test_an_exact_store_issue_number_resolves(): void
    {
        $issue = $this->storeIssue('FL-SI-000001');

        $result = $this->look('FL-SI-000001');

        $this->assertNotNull($result['resolved'], 'the movement history names issue numbers and nothing could look one up');
        $this->assertSame('store_issue', $result['resolved']['kind']);
        $this->assertSame($issue->id, $result['resolved']['id']);
    }

    /** A SCANNER SENDS THE CASE ON THE LABEL, and the master row may differ. */
    public function test_case_does_not_decide_whether_a_scan_is_found(): void
    {
        $result = $this->look('fl-resin-01');

        $this->assertNotNull($result['resolved'], 'sqlite = is case-sensitive and MySQL is not; both must agree here');
        $this->assertSame($this->resin->id, $result['resolved']['id']);
    }

    /* ---------------------------------------------------------------- *
     * The refusals to jump
     * ---------------------------------------------------------------- */

    /**
     * UNIQUE PER ITEM ONLY. Jumping would pick one item's batch out of
     * several and present it as the answer.
     */
    public function test_an_exact_batch_number_lists_rather_than_jumps(): void
    {
        Batch::create(['item_id' => $this->resin->id, 'batch_number' => 'FL-B-77']);

        $result = $this->look('FL-B-77');

        $this->assertNull($result['resolved'], 'batch_number is unique only within an item — it must never jump');
        $this->assertSame(['batch'], array_column($result['matches'], 'kind'));
    }

    public function test_an_exact_serial_number_lists_rather_than_jumps(): void
    {
        SerialNumber::create(['item_id' => $this->resin->id, 'serial_number' => 'FL-S-42']);

        $result = $this->look('FL-S-42');

        $this->assertNull($result['resolved'], 'serial_number is unique only within an item');
        $this->assertSame(['serial'], array_column($result['matches'], 'kind'));
    }

    /** The supplier's string, nullable and not unique — never ours to jump on. */
    public function test_a_supplier_lot_number_lists_rather_than_jumps(): void
    {
        $this->bag('FL-BAG-0002');

        $result = $this->look('SUP-LOT-9');

        $this->assertNull($result['resolved']);
        $this->assertContains('lot', array_column($result['matches'], 'kind'));
    }

    /** Two kinds answering the same term is exactly when a person must choose. */
    public function test_a_term_matching_two_kinds_returns_both_and_jumps_to_neither(): void
    {
        Batch::create(['item_id' => $this->resin->id, 'batch_number' => 'FL-RESIN-01']);

        $result = $this->look('FL-RESIN-01');

        $kinds = array_column($result['matches'], 'kind');
        sort($kinds);
        $this->assertSame(['batch', 'item'], $kinds, 'both spaces answer, and neither one wins');
        $this->assertNull($result['resolved'], 'an ambiguous term must never send the reader anywhere');
    }

    /** A partial term is a question, not an answer. */
    public function test_a_partial_term_lists_and_does_not_jump(): void
    {
        $result = $this->look('FL-RESIN');

        $this->assertNull($result['resolved'], 'a substring hit is not the same as holding the thing');
        $this->assertSame('item', $result['matches'][0]['kind']);
    }

    /* ---------------------------------------------------------------- *
     * Ordering, omission and the enumeration floor
     * ---------------------------------------------------------------- */

    /**
     * EXACT BEFORE PARTIAL, and the fixture is built so the exact row would
     * NOT come first by id — otherwise this passes for the wrong reason on
     * any table with one row in it.
     */
    public function test_the_exact_row_comes_before_the_rows_that_merely_contain_it(): void
    {
        Item::create(['sku' => 'FL-AAA-CODE-X', 'name' => 'Contains it', 'uom' => 'NOS']);
        Item::create(['sku' => 'CODE-X', 'name' => 'The exact one', 'uom' => 'NOS']);
        Item::create(['sku' => 'FL-ZZZ-CODE-X', 'name' => 'Contains it too', 'uom' => 'NOS']);

        $skus = array_column($this->look('CODE-X')['matches'], 'identifier');

        $this->assertSame('CODE-X', $skus[0], 'the row the reader is holding must be first, not third by id');
        $this->assertCount(3, $skus);
    }

    /**
     * OFF IS NOT EMPTY. Told "nothing matches", a storekeeper concludes the
     * bag is unknown to the ERP; the truth is that the ERP was not asked.
     */
    public function test_traceability_off_reports_bags_and_lots_as_omitted_rather_than_absent(): void
    {
        $this->bag('FL-BAG-0003');
        config(['production.traceability_enabled' => false]);

        $result = $this->look('FL-BAG-0003');

        $this->assertSame([], $result['matches'], 'the bag is genuinely not looked up');
        $this->assertSame(
            ['bag', 'lot'],
            array_column($result['omitted'], 'kind'),
            'and the caller is TOLD it was not looked up',
        );
        $this->assertStringContainsString('switched off', $result['omitted'][0]['reason']);
    }

    public function test_traceability_on_omits_nothing(): void
    {
        $this->assertSame([], $this->look('FL-RESIN-01')['omitted']);
    }

    /**
     * THE ENUMERATION FLOOR. issue_number is sequential, so a two-character
     * term would walk the factory's handover history for any inventory
     * reader.
     */
    public function test_a_term_too_short_to_be_held_in_a_hand_is_refused(): void
    {
        $this->getJson('/api/v1/inventory/lookup?q=SI')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_no_total_is_published_for_any_kind(): void
    {
        foreach (range(1, 3) as $n) {
            $this->storeIssue('FL-SI-00000'.$n);
        }

        $result = $this->look('FL-SI');

        $this->assertArrayNotHasKey('total', $result);
        foreach ($result['matches'] as $match) {
            $this->assertArrayNotHasKey('total', $match);
        }
    }

    /** The cap is real, and it is per kind. */
    public function test_a_kind_is_capped(): void
    {
        foreach (range(1, FactoryLookupService::PER_KIND + 5) as $n) {
            $this->storeIssue(sprintf('FL-SI-%06d', $n));
        }

        $this->assertCount(FactoryLookupService::PER_KIND, $this->look('FL-SI')['matches']);
    }

    /** A read, and inventory.view is the whole gate. */
    public function test_it_refuses_a_caller_without_inventory_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/v1/inventory/lookup?q=FL-RESIN-01')->assertForbidden();
    }

    /** FC-06: no rate rides along on any answer. */
    public function test_no_money_appears_in_any_match(): void
    {
        $this->bag('FL-BAG-0004');

        foreach ($this->look('FL-')['matches'] as $match) {
            foreach (array_keys($match) as $key) {
                $this->assertStringNotContainsString('cost', $key);
                $this->assertStringNotContainsString('rate', $key);
                $this->assertStringNotContainsString('price', $key);
            }
        }
    }
}
