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
 * THE SYNC-RESOLUTION EXPERIENCE: a failed voucher names the exact place its
 * mapping is fixed, a retry REGENERATES the payload from current mappings
 * rather than replaying the frozen one, the repair is recorded so the story
 * stays readable, and a voucher already in Tally is still refused whole.
 */
class SyncResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['tally-sync.view', 'tally-sync.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function failedEntry(string $error): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => 'nonexistent-model',
            'syncable_id' => 999,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_type' => 'Stock Journal', 'lines' => []],
            'status' => TallySyncStatus::Failed,
            'attempts' => 1,
            'error_message' => $error,
        ]);
    }

    public function test_a_known_refusal_names_the_exact_fix_and_an_unknown_one_stays_honest(): void
    {
        $missingItem = $this->failedEntry("Stock Item '100ml' does not exist!");
        $missingGodown = $this->failedEntry('Godown "Work In Progress" does not exist in Tally.');
        $mystery = $this->failedEntry('E_UNKNOWN 0x0007');

        $rows = collect($this->getJson('/api/v1/tally-sync/entries?per_page=50')
            ->assertSuccessful()
            ->json('data'))->keyBy('id');

        $this->assertStringContainsString('Product Standards', $rows[$missingItem->id]['fix']['sentence']);
        $this->assertStringContainsString('missing_tally=1', $rows[$missingItem->id]['fix']['path']);
        $this->assertStringContainsString('Factory Settings', $rows[$missingGodown->id]['fix']['sentence']);
        // An unrecognised error carries NO fix block rather than a wrong one.
        $this->assertNull($rows[$mystery->id]['fix']);
    }

    public function test_a_retry_records_the_repair_and_requeues(): void
    {
        $entry = $this->failedEntry("Stock Item '100ml' does not exist!");

        $retried = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('pending', $retried['status']);
        $this->assertNull($retried['error_message']);
        $this->assertCount(1, $retried['resolution_log']);
        $this->assertSame("Stock Item '100ml' does not exist!", $retried['resolution_log'][0]['previous_error']);

        // A second failure and retry APPENDS — the whole story survives.
        $entry->refresh()->update(['status' => TallySyncStatus::Failed, 'error_message' => 'Still unhappy']);
        $again = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")
            ->assertSuccessful()
            ->json('data');
        $this->assertCount(2, $again['resolution_log']);
    }

    public function test_a_voucher_already_in_tally_is_still_refused_whole(): void
    {
        $entry = $this->failedEntry('anything');
        $entry->update(['status' => TallySyncStatus::Synced, 'synced_at' => now(), 'error_message' => null]);

        $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")->assertStatus(422);
        $this->assertSame(TallySyncStatus::Synced, $entry->fresh()->status);
    }
}
