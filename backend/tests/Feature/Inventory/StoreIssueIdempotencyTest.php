<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE DOUBLE HANDOVER.
 *
 * A store issue was the one stock writer of the three with no guard at all.
 * A goods receipt replays on `receipt_key`; a shift production entry's
 * completion does a compare-and-swap on `batch_status` and refuses a second
 * attempt. `StoreIssueService::issue()` simply created the row — so two
 * identical POSTs (a double-tap, a request retried after a timeout, a browser
 * resend) produced two store issues, two issue numbers, and two transfer
 * pairs moving material into Production/WIP twice.
 *
 * NOTHING LOOKED WRONG AFTERWARDS, which is why it needed a test rather than
 * a reading. Both issues were real rows, every balance equalled the sum of
 * its movements, and `inventory:check-ledger` stayed green. The store had
 * simply handed over twice what it believed, and only a physical count would
 * ever have said so.
 *
 * The assertion that matters is the MOVEMENT COUNT, not the row count: a
 * second StoreIssue row is untidy, but a second pair of stock movements is
 * material walking out of the store.
 */
class StoreIssueIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private User $storeKeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'IK-RM', 'name' => 'IK Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'IK-WIP', 'name' => 'IK Work In Progress', 'is_active' => false]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->resin = Item::create([
            'sku' => 'IK-RESIN', 'name' => 'IK Resin', 'uom' => 'KGS',
            'is_active' => true, 'is_production_input' => true,
        ]);

        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '1000.0000',
            'average_cost' => '10.0000',
        ]);

        $this->storeKeeper = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.manage', 'web');
        $this->storeKeeper->givePermissionTo('inventory.manage');
        Sanctum::actingAs($this->storeKeeper);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'issue_key' => 'handover-0001',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '300.0000',
                'uom' => 'KGS',
            ]],
        ], $overrides);
    }

    private function transferCount(): int
    {
        return StockMovement::query()
            ->where('purpose', StockMovementPurpose::IssueToProduction->value)
            ->count();
    }

    private function storeBalance(): string
    {
        return bcadd((string) StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->value('quantity'), '0', 4);
    }

    public function test_the_same_key_twice_hands_the_material_over_once(): void
    {
        $first = $this->postJson('/api/v1/inventory/store-issues', $this->payload())->assertCreated();
        $second = $this->postJson('/api/v1/inventory/store-issues', $this->payload())->assertCreated();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'the retry must replay the original handover, not open a new one',
        );
        $this->assertSame($first->json('data.issue_number'), $second->json('data.issue_number'));

        $this->assertSame(1, StoreIssue::query()->count());

        // THE ONE THAT MATTERS. Two transfer legs (store out, WIP in) for one
        // handover — four would be material walking out of the store twice.
        $this->assertSame(2, $this->transferCount(), 'a replay must move no stock');
        $this->assertSame('700.0000', $this->storeBalance());
    }

    public function test_the_same_key_with_different_quantities_is_refused_rather_than_silently_replayed(): void
    {
        $this->postJson('/api/v1/inventory/store-issues', $this->payload())->assertCreated();

        // The storekeeper corrects the figure and resubmits with the same key.
        // Returning the first issue here would report success while writing
        // nothing, and they would believe the correction was recorded.
        $this->postJson('/api/v1/inventory/store-issues', $this->payload([
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '500.0000',
                'uom' => 'KGS',
            ]],
        ]))->assertStatus(422)->assertJsonValidationErrors('issue_key');

        $this->assertSame(1, StoreIssue::query()->count());
        $this->assertSame(2, $this->transferCount());
        $this->assertSame('700.0000', $this->storeBalance(), 'the refused correction moved nothing');
    }

    public function test_a_caller_that_sends_no_key_keeps_the_old_behaviour(): void
    {
        // Backward compatibility is deliberate: the column is nullable and an
        // integration that has never heard of the key must keep working. It
        // is also exactly why the FRONTEND always sends one.
        $body = $this->payload();
        unset($body['issue_key']);

        $this->postJson('/api/v1/inventory/store-issues', $body)->assertCreated();
        $this->postJson('/api/v1/inventory/store-issues', $body)->assertCreated();

        $this->assertSame(2, StoreIssue::query()->count());
        $this->assertSame(4, $this->transferCount());
        $this->assertSame('400.0000', $this->storeBalance());
    }

    public function test_two_different_keys_are_two_real_handovers(): void
    {
        $this->postJson('/api/v1/inventory/store-issues', $this->payload(['issue_key' => 'handover-A']))->assertCreated();
        $this->postJson('/api/v1/inventory/store-issues', $this->payload(['issue_key' => 'handover-B']))->assertCreated();

        $this->assertSame(2, StoreIssue::query()->count());
        $this->assertSame(4, $this->transferCount());
        $this->assertSame('400.0000', $this->storeBalance());
    }

    public function test_the_key_is_stored_so_a_later_retry_still_replays(): void
    {
        $response = $this->postJson('/api/v1/inventory/store-issues', $this->payload())->assertCreated();

        $issue = StoreIssue::query()->findOrFail($response->json('data.id'));

        $this->assertSame('handover-0001', $issue->issue_key);
        $this->assertNotNull($issue->issue_payload_hash, 'without the hash a corrected retry could not be told apart');
    }

    public function test_a_blank_key_is_treated_as_no_key_rather_than_as_one_shared_key(): void
    {
        // Two different handovers both sending "" must not collide on a single
        // empty-string key — that would refuse the second real issue.
        $this->postJson('/api/v1/inventory/store-issues', $this->payload(['issue_key' => '']))->assertCreated();
        $this->postJson('/api/v1/inventory/store-issues', $this->payload(['issue_key' => '   ']))->assertCreated();

        $this->assertSame(2, StoreIssue::query()->count());
        $this->assertSame(4, $this->transferCount());
    }
}
