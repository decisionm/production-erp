<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A failed voucher must be reachable, not just stored.
 *
 * The sync queue lists newest-first, 20 to a page. The dashboard sorts
 * failures to the top and counts them — but it can only count what the API
 * hands it, so a Tally rejection older than the newest 20 entries would sit
 * off the end of page one and be reported by nobody. This pins the
 * `per_page` passthrough the dashboard relies on to load the whole queue,
 * and the error message it puts in front of the accountant.
 */
class SyncQueueVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('tally-sync.manage', 'web');
        $user->givePermissionTo('tally-sync.manage');
        Sanctum::actingAs($user);
    }

    private function voucher(array $attributes = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => 'shift_production_entry',
            'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-1'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    public function test_the_queue_still_defaults_to_twenty_per_page(): void
    {
        $this->actAsStaff();

        foreach (range(1, 25) as $ignored) {
            $this->voucher();
        }

        $this->getJson('/api/v1/tally-sync/entries')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25);
    }

    public function test_per_page_reaches_a_failure_that_page_one_would_hide(): void
    {
        $this->actAsStaff();

        // The rejection that matters, buried under a day's worth of traffic.
        $stranded = $this->voucher([
            'payload' => ['voucher_number' => 'SPE-7'],
            'status' => TallySyncStatus::Failed,
            'attempts' => 2,
            'error_message' => 'Stock Item does not exist',
        ]);

        foreach (range(1, 30) as $ignored) {
            $this->voucher(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);
        }

        // Default page one is 30 newer vouchers — the failure is not on it.
        $firstPage = $this->getJson('/api/v1/tally-sync/entries')->assertOk();
        $this->assertNotContains($stranded->id, $firstPage->json('data.*.id'));

        $all = $this->getJson('/api/v1/tally-sync/entries?per_page=200')->assertOk();
        $this->assertContains($stranded->id, $all->json('data.*.id'));

        // And it arrives carrying the words the accountant has to act on.
        $failed = collect($all->json('data'))->firstWhere('id', $stranded->id);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('Stock Item does not exist', $failed['error_message']);
        $this->assertSame(2, $failed['attempts']);
    }

    public function test_an_absurd_per_page_is_clamped_rather_than_erroring(): void
    {
        // The dashboard asks for a big page deliberately; a bad or hostile
        // value must degrade to a sane list, never a 500 or an unbounded
        // dump of the entire table.
        $this->actAsStaff();
        $this->voucher();

        $this->getJson('/api/v1/tally-sync/entries?per_page=999999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1000);

        $this->getJson('/api/v1/tally-sync/entries?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }
}
