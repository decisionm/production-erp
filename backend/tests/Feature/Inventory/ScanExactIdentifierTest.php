<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A SUBSTRING SEARCH IS NOT AN IDENTIFIER LOOKUP, AND A PAGE SIZE IS NOT A
 * SAFE ANSWER TO ONE.
 *
 * The Stock page's scanner asked the server `?search=<code>&per_page=50` and
 * then picked the exact match out of the reply. That is fine right up until
 * the scanned code is a PREFIX of enough newer codes: `LOT-4` is a substring
 * of `LOT-40` … `LOT-4059`, every one of them a higher id, and the list is
 * ordered newest first. Fifty decoys fill the page, the real row is on page
 * two, and a barcode this system printed comes back as "no item, batch, or
 * serial number matches" — with correct stock standing behind it and a
 * scanner in someone's hand.
 *
 * Raising the cap does not fix it; it moves it. `code` matches the WHOLE
 * value, so how many other rows contain it stops being a question the answer
 * depends on.
 *
 * The `search` half of each test below is not decoration: it is the defect,
 * pinned, so that nobody later "simplifies" the scan back onto `search`.
 */
class ScanExactIdentifierTest extends TestCase
{
    use RefreshDatabase;

    /** Comfortably past the 50 the scanner used to ask for. */
    private const DECOYS = 60;

    private Item $batchTracked;

    private Item $serialTracked;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->batchTracked = Item::create([
            'sku' => 'RM-MB', 'name' => 'Masterbatch Amber', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::Batch,
        ]);
        $this->serialTracked = Item::create([
            'sku' => 'AS-MOULD', 'name' => 'Mould Insert', 'uom' => 'Nos',
            'tracking_type' => ItemTrackingType::Serial,
        ]);
    }

    public function test_an_exact_batch_code_is_found_behind_sixty_substring_decoys(): void
    {
        $real = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'LOT-4']);
        $this->seedBatchDecoys();

        // THE DEFECT, PINNED. Every decoy CONTAINS 'LOT-4' and every one of
        // them is newer, so the page the scanner read never reached the row.
        $hidden = $this->getJson('/api/v1/inventory/batches?search=LOT-4&per_page=50')
            ->assertSuccessful();
        $this->assertNotContains($real->id, $this->idsOf($hidden), 'a substring search reached past its page size');

        // THE FIX. Whole-value match — the decoys are not answers to it.
        $this->getJson('/api/v1/inventory/batches?code=LOT-4&per_page=50')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $real->id)
            ->assertJsonPath('data.0.batch_number', 'LOT-4');
    }

    public function test_an_exact_serial_code_is_found_behind_sixty_substring_decoys(): void
    {
        $real = SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-7',
            'status' => SerialNumberStatus::Registered,
        ]);
        $this->seedSerialDecoys();

        $hidden = $this->getJson('/api/v1/inventory/serial-numbers?search=SN-7&per_page=50')
            ->assertSuccessful();
        $this->assertNotContains($real->id, $this->idsOf($hidden), 'a substring search reached past its page size');

        $this->getJson('/api/v1/inventory/serial-numbers?code=SN-7&per_page=50')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $real->id)
            ->assertJsonPath('data.0.serial_number', 'SN-7');
    }

    /**
     * THE SMALLEST PAGE THERE IS still finds it. If the answer survives
     * `per_page=1` it does not depend on the page size at all, which is the
     * whole claim.
     */
    public function test_the_exact_match_does_not_depend_on_the_page_size(): void
    {
        $real = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'LOT-4']);
        $this->seedBatchDecoys();

        $this->getJson('/api/v1/inventory/batches?code=LOT-4&per_page=1')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $real->id)
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * `where('batch_number', $code)` would have passed on MySQL, whose default
     * collation compares case-insensitively, and failed on SQLite, whose `=`
     * does not — a scan resolving on the live instance and nowhere a test
     * could see it. Matched on `lower()` for that reason; this is the test
     * that would have caught the plain equality.
     */
    public function test_the_exact_match_is_case_insensitive_on_every_driver(): void
    {
        $batch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'LOT-4']);
        $serial = SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-7',
            'status' => SerialNumberStatus::Registered,
        ]);

        $this->getJson('/api/v1/inventory/batches?code=lot-4')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $batch->id);

        $this->getJson('/api/v1/inventory/serial-numbers?code=sn-7')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $serial->id);
    }

    /** A code that IS a substring of real rows but is nobody's number. */
    public function test_an_unknown_code_matches_nothing_rather_than_the_rows_containing_it(): void
    {
        $this->seedBatchDecoys();

        $this->getJson('/api/v1/inventory/batches?code=LOT-4')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /** The parameter is optional; the bare list answers exactly as before. */
    public function test_the_lists_answer_unchanged_without_the_parameter(): void
    {
        Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'LOT-4']);
        $this->seedBatchDecoys();

        $this->getJson('/api/v1/inventory/batches')
            ->assertSuccessful()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', self::DECOYS + 1);

        $this->getJson('/api/v1/inventory/batches?code=')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', self::DECOYS + 1);
    }

    /** `code` narrows within one item, it does not replace `item_id`. */
    public function test_the_exact_match_still_honours_the_item_filter(): void
    {
        $other = Item::create([
            'sku' => 'RM-MB2', 'name' => 'Masterbatch White', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::Batch,
        ]);
        Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'LOT-4']);
        $theirs = Batch::create(['item_id' => $other->id, 'batch_number' => 'LOT-4']);

        $this->getJson("/api/v1/inventory/batches?code=LOT-4&item_id={$other->id}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);
    }

    // ---- helpers -------------------------------------------------------------

    /** @return list<int> */
    private function idsOf(TestResponse $response): array
    {
        return array_map(static fn (array $row) => (int) $row['id'], $response->json('data'));
    }

    /** Sixty newer numbers, every one of them CONTAINING 'LOT-4'. */
    private function seedBatchDecoys(): void
    {
        foreach (range(1, self::DECOYS) as $index) {
            Batch::create([
                'item_id' => $this->batchTracked->id,
                'batch_number' => 'LOT-4'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function seedSerialDecoys(): void
    {
        foreach (range(1, self::DECOYS) as $index) {
            SerialNumber::create([
                'item_id' => $this->serialTracked->id,
                'serial_number' => 'SN-7'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'status' => SerialNumberStatus::Registered,
            ]);
        }
    }
}
