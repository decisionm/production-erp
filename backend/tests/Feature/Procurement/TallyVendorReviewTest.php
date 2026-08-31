<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\TallyVendorReviewService;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\TallyVendorReviewDismissal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TALLY PROPOSES; A PERSON DECIDES. The vendor master changes only when an
 * Owner/Accounts login confirms a difference it can see.
 *
 * The masters pull mirrors ledgers and stops. Nothing in this suite passes if
 * a sync alone can create or correct a vendor, because that is exactly the
 * failure the review screen exists to prevent: a background job that quietly
 * invented hundreds of vendors, or silently changed one, is what nobody
 * notices until an order goes to the wrong party.
 *
 * THE GSTIN IS NOT AN IDENTITY IN THESE BOOKS, and the hardest test here pins
 * it. Measured on the live company's own All Masters export, 23 GSTINs appear
 * on MORE THAN ONE ledger — two Sundry Creditors among them share one. So a
 * GSTIN that could mean two parties must produce a row that SAYS SO and
 * refuses to apply anything. This repository has already paid once for the
 * other behaviour, when a first-name-only identity map put one person's
 * employee number on another.
 *
 * Every name, GSTIN and rate below is invented. Supplier identity is
 * Owner/Accounts (FC-06) and does not belong in a test fixture.
 */
class TallyVendorReviewTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'Sundry Creditors';

    /** A GSTIN-shaped value that is not any real party's. */
    private function gstin(string $state, string $tail): string
    {
        return $state.'AAAAA0000A1Z'.$tail;
    }

    private function actAsAccounts(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['finance.view', 'finance.manage', 'procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    private function actAsFloor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        return $user;
    }

    private function ledger(string $name, array $overrides = []): Ledger
    {
        return Ledger::create([
            'tally_guid' => 'led-'.md5($name),
            'name' => $name,
            'tally_group_name' => self::GROUP,
            'tally_synced_at' => Carbon::parse('2026-08-30 09:00:00'),
            ...$overrides,
        ]);
    }

    private function selectGroup(): void
    {
        $this->putJson('/api/v1/procurement/tally/vendor-review/groups', ['groups' => [self::GROUP]])->assertOk();
    }

    private function queue(): array
    {
        return $this->getJson('/api/v1/procurement/tally/vendor-review')->assertOk()->json('data');
    }

    // ── Selection is an owner act ────────────────────────────────────────

    public function test_nothing_is_proposed_until_the_owner_names_the_groups(): void
    {
        $this->actAsAccounts();
        $this->ledger('Synthetic Supplies');

        $queue = $this->queue();

        $this->assertSame([], $queue['groups']);
        $this->assertSame([], $queue['rows']);
        // The census is still offered, so the choice can be made from data.
        $this->assertSame([self::GROUP => 1], $queue['group_census']);
    }

    public function test_a_group_that_is_not_in_the_mirror_is_refused_rather_than_watching_nothing(): void
    {
        $this->actAsAccounts();
        $this->ledger('Synthetic Supplies');

        $this->putJson('/api/v1/procurement/tally/vendor-review/groups', ['groups' => ['Sundry Craditors']])
            ->assertStatus(422);

        $this->assertSame([], $this->queue()['groups']);
    }

    // ── New vendors ──────────────────────────────────────────────────────

    public function test_a_ledger_with_no_matching_vendor_is_proposed_as_new_and_creates_nothing_by_itself(): void
    {
        $this->actAsAccounts();
        $this->ledger('Synthetic Supplies', ['gstin' => $this->gstin('33', 'A'), 'email' => 'a@example.test', 'phone' => '0400000000']);
        $this->selectGroup();

        $queue = $this->queue();

        $this->assertCount(1, $queue['rows']);
        $this->assertSame('new', $queue['rows'][0]['kind']);
        $this->assertSame('Synthetic Supplies', $queue['rows'][0]['proposed']['name']);
        $this->assertSame('a@example.test', $queue['rows'][0]['proposed']['email']);
        // The GST state code is READ from the GSTIN's first two digits, which
        // is the format's definition, not an inference.
        $this->assertSame('33', $queue['rows'][0]['proposed']['state_code']);
        // Reading the queue is not confirming it.
        $this->assertSame(0, Vendor::count());
    }

    public function test_confirming_a_new_row_creates_the_vendor_with_a_minted_code_and_the_tally_identity(): void
    {
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies', ['gstin' => $this->gstin('33', 'A')]);
        $this->selectGroup();

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-new', ['tally_ledger_guid' => $ledger->tally_guid])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Synthetic Supplies');

        $vendor = Vendor::sole();
        $this->assertSame('V-0001', $vendor->code);
        $this->assertSame($ledger->tally_guid, $vendor->tally_ledger_guid);
        $this->assertSame('Synthetic Supplies', $vendor->tally_ledger_name);
        // Address does not exist on a Tally ledger in any usable form and is
        // never fabricated.
        $this->assertNull($vendor->address);

        // Confirmed, so no longer owed.
        $this->assertSame([], $this->queue()['rows']);
    }

    public function test_a_name_another_vendor_already_owns_is_reported_and_refused_rather_than_merged(): void
    {
        $this->actAsAccounts();
        Vendor::create(['code' => 'VEN-OLD', 'name' => 'Synthetic Supplies', 'is_active' => true]);
        $ledger = $this->ledger('Synthetic Supplies');
        $this->selectGroup();

        $this->assertSame('VEN-OLD', $this->queue()['rows'][0]['name_clash']['code']);

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-new', ['tally_ledger_guid' => $ledger->tally_guid])
            ->assertStatus(422);

        $this->assertSame(1, Vendor::count());
    }

    // ── Conflicts on a vendor that already exists ────────────────────────

    public function test_a_linked_vendor_whose_details_tally_disagrees_with_is_raised_as_a_conflict(): void
    {
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies', ['gstin' => $this->gstin('33', 'A'), 'phone' => '0400000000']);
        // The ledger name already agrees, so it is not among the differences
        // — the row below is exactly the three that genuinely differ.
        $vendor = Vendor::create([
            'code' => 'V-0001', 'name' => 'Synthetic Supplies', 'phone' => '0499999999',
            'tally_ledger_name' => 'Synthetic Supplies', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        $row = $this->queue()['rows'][0];

        $this->assertSame('conflict', $row['kind']);
        $this->assertSame('ledger_guid', $row['match_basis']);
        $this->assertEqualsCanonicalizing(
            ['phone', 'gstin', 'state_code'],
            array_column($row['differences'], 'field'),
        );
        $this->assertSame('0499999999', collect($row['differences'])->firstWhere('field', 'phone')['current']);
        $this->assertSame('0400000000', collect($row['differences'])->firstWhere('field', 'phone')['proposed']);
    }

    public function test_a_linked_vendor_with_no_ledger_name_recorded_is_offered_the_one_tally_uses(): void
    {
        // `tally_ledger_name` is what a voucher must CALL this party, and it
        // has been free text Accounts types in since Phase 6. A vendor linked
        // to a ledger but carrying no such name is a voucher waiting to be
        // addressed to nobody, so the review offers the ledger's own name
        // rather than leaving it to be retyped from a screen next door.
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies');
        $vendor = Vendor::create(['code' => 'V-0001', 'name' => 'Synthetic Supplies', 'is_active' => true]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        $row = $this->queue()['rows'][0];
        $this->assertSame(['tally_ledger_name'], array_column($row['differences'], 'field'));

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'vendor_id' => $vendor->id,
            'fields' => ['tally_ledger_name'],
        ])->assertOk();

        $this->assertSame('Synthetic Supplies', $vendor->refresh()->tally_ledger_name);
        $this->assertSame([], $this->queue()['rows']);
    }

    public function test_confirming_takes_only_the_named_fields_and_leaves_the_rest_alone(): void
    {
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies', ['gstin' => $this->gstin('33', 'A'), 'phone' => '0400000000']);
        $vendor = Vendor::create([
            'code' => 'V-0001', 'name' => 'Synthetic Supplies', 'phone' => '0499999999',
            'tally_ledger_name' => 'Synthetic Supplies', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'vendor_id' => $vendor->id,
            'fields' => ['gstin'],
        ])->assertOk();

        $vendor->refresh();
        $this->assertSame($this->gstin('33', 'A'), $vendor->gstin);
        // Not named, so untouched — a person may take the GSTIN and keep the
        // phone number they typed.
        $this->assertSame('0499999999', $vendor->phone);
    }

    public function test_confirming_a_rename_onto_another_vendors_name_is_refused_like_a_new_one_is(): void
    {
        // THE SHAPE IS IN THE LIVE BOOKS: "Accurate Industries" beside
        // "Accurate Industries -Purchase", two Sundry Creditors sharing a
        // GSTIN. If Tally drops the suffix, confirming the rename would make
        // the second row for one supplier that the create path already
        // refuses to make. `vendors.name` is not unique, so only this check
        // stops it.
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies', ['tally_guid' => 'led-purchase-twin']);
        $vendor = Vendor::create([
            'code' => 'V-0002', 'name' => 'Synthetic Supplies -Purchase',
            'tally_ledger_name' => 'Synthetic Supplies', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => 'led-purchase-twin'])->save();
        Vendor::create(['code' => 'V-0001', 'name' => 'Synthetic Supplies', 'is_active' => true]);
        $this->selectGroup();

        // It IS raised as a difference — the person must see it.
        $this->assertSame(['name'], array_column($this->queue()['rows'][0]['differences'], 'field'));

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => 'led-purchase-twin',
            'vendor_id' => $vendor->id,
            'fields' => ['name'],
        ])->assertStatus(422);

        $this->assertSame('Synthetic Supplies -Purchase', $vendor->refresh()->name);
    }

    public function test_a_rename_to_a_name_nobody_else_holds_is_applied_normally(): void
    {
        // The guard above must refuse a COLLISION, not renaming as such.
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies Ltd');
        $vendor = Vendor::create([
            'code' => 'V-0001', 'name' => 'Synthetic Supplies',
            'tally_ledger_name' => 'Synthetic Supplies Ltd', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'vendor_id' => $vendor->id,
            'fields' => ['name'],
        ])->assertOk();

        $this->assertSame('Synthetic Supplies Ltd', $vendor->refresh()->name);
    }

    public function test_tally_silence_never_clears_a_value_the_erp_holds(): void
    {
        $this->actAsAccounts();
        // The mirror carries no contact for this party, which is the ordinary
        // case: 4 emails across 1742 ledgers in the live books.
        $ledger = $this->ledger('Synthetic Supplies');
        $vendor = Vendor::create([
            'code' => 'V-0001', 'name' => 'Synthetic Supplies',
            'email' => 'typed@example.test', 'phone' => '0499999999',
            'tally_ledger_name' => 'Synthetic Supplies', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        // Nothing to decide: Tally not carrying a value is not a proposal to
        // remove one.
        $this->assertSame([], $this->queue()['rows']);

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'vendor_id' => $vendor->id,
            'fields' => ['email'],
        ])->assertStatus(422);

        $this->assertSame('typed@example.test', $vendor->refresh()->email);
    }

    // ── Matching on a GSTIN, and refusing to when it is ambiguous ────────

    public function test_a_unique_gstin_matches_an_unlinked_vendor_and_confirming_records_the_identity(): void
    {
        $this->actAsAccounts();
        $gstin = $this->gstin('33', 'A');
        $ledger = $this->ledger('Synthetic Supplies Ltd', ['gstin' => $gstin, 'phone' => '0400000000']);
        $vendor = Vendor::create(['code' => 'VEN-OLD', 'name' => 'Synthetic Supplies', 'gstin' => $gstin, 'is_active' => true]);
        $this->selectGroup();

        $row = $this->queue()['rows'][0];
        $this->assertSame('gstin', $row['match_basis']);
        $this->assertTrue($row['links_identity']);

        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-fields', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'vendor_id' => $vendor->id,
            'fields' => ['phone'],
        ])->assertOk();

        // The identity is recorded, so the next sync matches exactly instead
        // of re-deriving the same guess.
        $this->assertSame($ledger->tally_guid, $vendor->refresh()->tally_ledger_guid);
    }

    public function test_a_gstin_carried_by_two_ledgers_is_reported_ambiguous_and_applies_nothing(): void
    {
        $this->actAsAccounts();
        $gstin = $this->gstin('33', 'A');

        // The measured live case: one GSTIN, two Sundry Creditors ledgers.
        $ledger = $this->ledger('Synthetic Supplies', ['gstin' => $gstin]);
        $this->ledger('Synthetic Supplies -Purchase', ['gstin' => $gstin]);

        $vendor = Vendor::create(['code' => 'VEN-OLD', 'name' => 'Synthetic Supplies', 'gstin' => $gstin, 'is_active' => true]);
        $this->selectGroup();

        $rows = collect($this->queue()['rows']);
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('ambiguous', $row['kind']);
            $this->assertSame('gstin_ambiguous', $row['match_basis']);
            $this->assertSame([$vendor->id], array_column($row['ambiguous_with'], 'vendor_id'));
            // No difference is offered, because there is no safe one to offer.
            $this->assertSame([], $row['differences']);
        }

        // And the master is untouched by the mere existence of the collision.
        $this->assertSame('Synthetic Supplies', $vendor->refresh()->name);

        // An ambiguous row cannot be confirmed into the vendor either.
        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-new', ['tally_ledger_guid' => $ledger->tally_guid])
            ->assertStatus(422);
        $this->assertSame(1, Vendor::count());
    }

    public function test_two_erp_vendors_sharing_a_gstin_are_ambiguous_too(): void
    {
        $this->actAsAccounts();
        $gstin = $this->gstin('33', 'A');
        $this->ledger('Synthetic Supplies', ['gstin' => $gstin]);
        Vendor::create(['code' => 'VEN-A', 'name' => 'Synthetic A', 'gstin' => $gstin, 'is_active' => true]);
        Vendor::create(['code' => 'VEN-B', 'name' => 'Synthetic B', 'gstin' => $gstin, 'is_active' => true]);
        $this->selectGroup();

        $row = $this->queue()['rows'][0];
        $this->assertSame('ambiguous', $row['kind']);
        $this->assertEqualsCanonicalizing(['VEN-A', 'VEN-B'], array_column($row['ambiguous_with'], 'code'));
    }

    // ── Dismissal ────────────────────────────────────────────────────────

    public function test_a_dismissed_difference_stays_away_only_while_tally_says_the_same_thing(): void
    {
        $this->actAsAccounts();
        $ledger = $this->ledger('Synthetic Supplies', ['phone' => '0400000000']);
        $vendor = Vendor::create([
            'code' => 'V-0001', 'name' => 'Synthetic Supplies', 'phone' => '0499999999',
            'tally_ledger_name' => 'Synthetic Supplies', 'is_active' => true,
        ]);
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
        $this->selectGroup();

        $this->postJson('/api/v1/procurement/tally/vendor-review/dismiss', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'field' => 'phone',
        ])->assertOk();

        $this->assertSame([], $this->queue()['rows']);

        // Tally now says something DIFFERENT. Setting one fact aside must not
        // hide the next one.
        $ledger->update(['phone' => '0411111111']);

        $row = $this->queue()['rows'][0];
        $this->assertSame('0411111111', collect($row['differences'])->firstWhere('field', 'phone')['proposed']);
    }

    public function test_a_ledger_dismissed_as_not_a_vendor_stops_being_proposed(): void
    {
        $this->actAsAccounts();
        // The measured hazard: an INTEREST ledger sitting among the parties.
        $ledger = $this->ledger('Interest Payable');
        $this->selectGroup();

        $this->assertCount(1, $this->queue()['rows']);

        $this->postJson('/api/v1/procurement/tally/vendor-review/dismiss', [
            'tally_ledger_guid' => $ledger->tally_guid,
            'field' => TallyVendorReviewDismissal::FIELD_ALL,
        ])->assertOk();

        $this->assertSame([], $this->queue()['rows']);
        $this->assertSame(0, Vendor::count());
    }

    // ── Provenance and access ────────────────────────────────────────────

    public function test_every_row_carries_the_tally_source_and_when_it_was_last_synced(): void
    {
        $this->actAsAccounts();
        $this->ledger('Synthetic Supplies');
        $this->selectGroup();

        $queue = $this->queue();

        $this->assertSame('tally', $queue['rows'][0]['source']);
        $this->assertSame('2026-08-30T09:00:00+00:00', $queue['rows'][0]['tally_synced_at']);
        $this->assertSame('2026-08-30T09:00:00+00:00', $queue['last_synced_at']);
    }

    public function test_a_floor_login_may_not_read_or_change_the_review(): void
    {
        $this->actAsFloor();

        $this->getJson('/api/v1/procurement/tally/vendor-review')->assertForbidden();
        $this->postJson('/api/v1/procurement/tally/vendor-review/confirm-new', ['tally_ledger_guid' => 'x'])->assertForbidden();
    }

    public function test_the_reviewable_fields_are_the_ones_a_ledger_can_speak_to(): void
    {
        // Pinned so a column added to the vendor form does not silently become
        // something Tally may overwrite.
        $this->assertSame(
            ['name', 'email', 'phone', 'gstin', 'state_code', 'tally_ledger_name'],
            TallyVendorReviewService::REVIEWABLE_FIELDS,
        );
    }
}
