<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DISMISS IS A ONE-WAY DOOR AND A NARROW ONE. A failed voucher can be
 * written off — never deleted — and once written off it is dead to the
 * whole pipeline: the agent can never collect it, Retry refuses to
 * resurrect it, and the act itself is on the record. Anything not failed
 * (pending, or worse, already in Tally) is refused whole, because a
 * "dismissed" label over a voucher that is mid-flight or in the books
 * would be a lie the accountant acts on.
 *
 * Exists for a live situation: three July demo vouchers sat failed in the
 * queue, one stray Resync away from posting demo data into the real books
 * now that the factory's Tally is open on the correct company.
 */
class DismissedVoucherTest extends TestCase
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

    private function entry(TallySyncStatus $status, ?string $error = null): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => 'nonexistent-model',
            'syncable_id' => 999,
            'tally_voucher_type' => 'Sales',
            'payload' => ['voucher_type' => 'Sales', 'voucher_number' => 'INV-1', 'lines' => []],
            'status' => $status,
            'attempts' => $status === TallySyncStatus::Failed ? 6 : 0,
            'error_message' => $error,
        ]);
    }

    public function test_dismissing_a_failed_voucher_records_the_act_and_keeps_the_history(): void
    {
        $entry = $this->entry(TallySyncStatus::Failed, "Could not set 'SVCurrentCompany'…");

        $dismissed = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/dismiss")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('dismissed', $dismissed['status']);
        // Not a repair — the reason it failed stays readable on the row.
        $this->assertSame("Could not set 'SVCurrentCompany'…", $dismissed['error_message']);
        $this->assertCount(1, $dismissed['resolution_log']);
        $this->assertSame('Dismissed — will never be sent to Tally.', $dismissed['resolution_log'][0]['note']);
        $this->assertSame("Could not set 'SVCurrentCompany'…", $dismissed['resolution_log'][0]['previous_error']);
    }

    public function test_a_dismissed_voucher_carries_no_fix_advice(): void
    {
        // An error the resource DOES recognise — on a failed row it names
        // Product Standards as the fix. Once the voucher is written off,
        // "go fix the mapping, then retry" is advice for a repair nobody
        // wants, so the fix block must vanish with the dismissal.
        $entry = $this->entry(TallySyncStatus::Failed, "Stock Item '100ml' does not exist!");

        $dismissed = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/dismiss")
            ->assertSuccessful()
            ->json('data');

        $this->assertNull($dismissed['fix']);
    }

    public function test_a_pending_voucher_cannot_be_written_off_mid_flight(): void
    {
        $entry = $this->entry(TallySyncStatus::Pending);

        $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/dismiss")
            ->assertStatus(422)
            ->assertJsonPath('errors.entry.0', 'Only a failed voucher can be dismissed — INV-1 is pending. '
                .'A voucher the agent may still be posting cannot be written off mid-flight; wait for its result first.');

        $this->assertSame(TallySyncStatus::Pending, $entry->fresh()->status);
    }

    public function test_a_voucher_already_in_tally_is_refused_whole(): void
    {
        $entry = $this->entry(TallySyncStatus::Synced);
        $entry->update(['synced_at' => now()]);

        $response = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/dismiss")->assertStatus(422);

        // Mirrors retry()'s double-post guard language: the books already
        // hold this voucher, so the honest answer is "go look at Tally".
        $this->assertStringContainsString('already in Tally as INV-1', $response->json('errors.entry.0'));
        $this->assertStringContainsString('check Tally before anything else', $response->json('errors.entry.0'));
        $this->assertSame(TallySyncStatus::Synced, $entry->fresh()->status);
    }

    public function test_the_agent_can_never_collect_a_dismissed_voucher(): void
    {
        $dismissed = $this->entry(TallySyncStatus::Failed, 'demo-era failure');
        app(TallySyncService::class)->dismiss($dismissed);

        $stillQueued = $this->entry(TallySyncStatus::Pending);

        // Both doors: the service the agent endpoint wraps, and the wire.
        $offered = app(TallySyncService::class)->pending()->pluck('id');
        $this->assertFalse($offered->contains($dismissed->id));
        $this->assertTrue($offered->contains($stillQueued->id));

        // The wire door polls as the agent itself — a token scoped to
        // exactly what the factory desktop's token carries.
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), ['tally-sync:poll']);
        $wireIds = collect($this->getJson('/api/v1/tally-sync/pending')->assertSuccessful()->json('data'))
            ->pluck('id');
        $this->assertFalse($wireIds->contains($dismissed->id));

        // And it stays uncollected: delivered_at was never stamped for it.
        $this->assertNull($dismissed->fresh()->delivered_at);
    }

    public function test_retry_cannot_resurrect_a_dismissed_voucher(): void
    {
        $entry = $this->entry(TallySyncStatus::Failed, 'demo-era failure');
        app(TallySyncService::class)->dismiss($entry);

        $response = $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")->assertStatus(422);

        $this->assertStringContainsString('INV-1 was dismissed — it is never to be sent to Tally.', $response->json('errors.entry.0'));
        $this->assertSame(TallySyncStatus::Dismissed, $entry->fresh()->status);
    }
}
