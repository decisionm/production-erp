<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WHICH GODOWN DOES A VOUCHER POST UNDER, WHEN THE WAREHOUSE TABLE HOLDS
 * MORE THAN ONE TALLY IDENTITY?
 *
 * This factory's Tally has exactly ONE godown — the company godown — and
 * every line the accountant has ever booked uses it. The seven real Purchase
 * Order vouchers the owner exported on 28-Aug all allocate to that single
 * name. TallyGodownResolver was written to that reality: an ERP location
 * with no Tally identity of its own posts under "the sole Tally-linked
 * warehouse".
 *
 * That rule stopped working, and the reason is not the rule. The rehearsal
 * database holds seven Tally-linked warehouses, and their guids carry TWO
 * different Tally company ids: six from one, and one from the company this
 * instance is actually bound to. "Exactly one" therefore counts seven, finds
 * no sole godown, and every purchase order refuses to stage with
 * `godown_unresolved`.
 *
 * The masters pull already knows which company it came from, and already
 * REFUSES a pull from any other company (the single-tenant guard). It simply
 * never wrote that company onto a godown it was updating: HierarchyUpsert
 * applies create-time defaults on create only. So the fact needed to tell the
 * seven apart was received and discarded.
 *
 * Two slices, in order, because the second cannot discriminate without the
 * first:
 *   A. a godown records the Tally company it came from, on update as well as
 *      on create;
 *   B. the sole-godown lookup narrows to the BOUND company's godowns.
 *
 * B NARROWS, IT DOES NOT REQUIRE, and this header used to say the opposite.
 * It described the first design — company mandatory, anything unknown not a
 * candidate — which was written, tested and then rejected because it broke
 * thirty-two tests for a real reason: no godown records a company yet, so a
 * mandatory rule resolves NOTHING until a fresh pull runs, and resolveName()
 * then falls back to the warehouse's own name, sending Tally a godown it does
 * not have.
 *
 * What ships: narrow only when at least one linked warehouse records the bound
 * company. A table that cannot be narrowed is counted whole, exactly as
 * before, so every previously-resolving system keeps resolving to the same
 * godown. That is a compatibility fallback, NOT a fail-closed guarantee, and
 * saying so plainly is the point of this paragraph — the next reader of this
 * file must not approve it believing an unknown company can never be chosen.
 * test_a_table_that_records_no_company_at_all_is_counted_as_before pins it.
 */
class GodownCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'SWAASHPET POLYMERS PVT LTD Testing';

    private function actAsAgent(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), ['tally-sync:masters']);
    }

    private function pullGodowns(array $godowns, string $company = self::COMPANY): void
    {
        $this->postJson('/api/v1/tally-sync/masters', [
            'company' => $company,
            'godowns' => $godowns,
        ])->assertOk();
    }

    // ---- Slice A: a godown records the company it came from ----------------

    public function test_a_new_godown_records_the_tally_company_it_came_from(): void
    {
        $this->actAsAgent();

        $this->pullGodowns([['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']]);

        $this->assertSame(
            self::COMPANY,
            Warehouse::where('tally_guid', 'gd-company')->value('tally_company'),
        );
    }

    /**
     * THE ONE THAT WAS MISSING. A godown that already exists is updated, not
     * created, and the update wrote only the name and the parent name — so
     * every godown pulled before this recorded no company at all.
     */
    public function test_a_re_pull_records_the_company_on_a_godown_that_has_none(): void
    {
        $this->actAsAgent();

        // The row as it exists today: Tally-linked, company unknown.
        Warehouse::create([
            'code' => 'SWAASHPET-POLYMERS-PVT-LTD',
            'name' => 'SWAASHPET POLYMERS PVT LTD',
            'tally_guid' => 'gd-company',
            'is_active' => true,
        ]);

        $this->pullGodowns([['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']]);

        $this->assertSame(
            self::COMPANY,
            Warehouse::where('tally_guid', 'gd-company')->value('tally_company'),
        );
    }

    /**
     * A PULL THAT NAMES NO COMPANY MUST NOT BLANK THE ONE ALREADY RECORDED.
     *
     * `company` is optional on the masters request, and the binding guard only
     * refuses a pull naming a DIFFERENT company — a pull naming none passes
     * straight through. The only thing standing between that and every godown
     * losing its company is one array_filter in WarehouseService. Nothing
     * pinned it, so deleting that filter left all the other tests here green
     * while quietly disarming the narrowing this whole file exists to hold.
     */
    public function test_a_pull_that_names_no_company_leaves_the_recorded_one_alone(): void
    {
        $this->actAsAgent();

        $this->pullGodowns([['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']]);
        $this->assertSame(self::COMPANY, Warehouse::where('tally_guid', 'gd-company')->value('tally_company'));

        $this->postJson('/api/v1/tally-sync/masters', [
            'godowns' => [['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']],
        ])->assertOk();

        $this->assertSame(
            self::COMPANY,
            Warehouse::where('tally_guid', 'gd-company')->value('tally_company'),
            'a pull carrying no company blanked the company already recorded',
        );
    }

    /**
     * The reason create-time defaults are not simply applied on update: the
     * warehouse CODE is the ERP's, and a person may rename it. A pull must
     * not reset it.
     */
    public function test_a_re_pull_does_not_reset_a_code_a_person_changed(): void
    {
        $this->actAsAgent();

        $this->pullGodowns([['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']]);
        Warehouse::where('tally_guid', 'gd-company')->update(['code' => 'MAIN']);

        $this->pullGodowns([['guid' => 'gd-company', 'name' => 'SWAASHPET POLYMERS PVT LTD']]);

        $this->assertSame('MAIN', Warehouse::where('tally_guid', 'gd-company')->value('code'));
    }

    // ---- Slice B: only the BOUND company's godown can be the sole one ------

    /** The state that broke it: godowns from two Tally companies in one table. */
    private function twoCompaniesOfGodowns(): void
    {
        Warehouse::create([
            'code' => 'SWAASHPET-POLYMERS-PVT-LTD',
            'name' => 'SWAASHPET POLYMERS PVT LTD',
            'tally_guid' => 'gd-ours',
            'tally_company' => self::COMPANY,
            'is_active' => true,
        ]);

        foreach (['RM Store', 'FG Store', 'Scrap Yard', 'Dispatch Bay', 'Main Location', 'Packing Material Store'] as $i => $name) {
            Warehouse::create([
                'code' => 'STALE-'.$i,
                'name' => $name,
                'tally_guid' => 'gd-other-'.$i,
                'tally_company' => 'Some Other Company',
                'is_active' => true,
            ]);
        }
    }

    private function bindCompany(string $company = self::COMPANY): void
    {
        app(AppSettingService::class)->set(TallySettingsController::KEY_COMPANY, $company);
    }

    public function test_the_sole_godown_ignores_godowns_of_another_tally_company(): void
    {
        $this->bindCompany();
        $this->twoCompaniesOfGodowns();

        $this->assertSame(
            'SWAASHPET POLYMERS PVT LTD',
            app(TallyGodownResolver::class)->soleTallyGodownName(),
        );
    }

    /**
     * THE NARROWING IS A TIE-BREAKER, NOT A PRECONDITION.
     *
     * No godown anywhere records a company yet, because the pull only ever
     * wrote it on create. A rule that REQUIRED one would resolve nothing on a
     * live instance until a fresh pull ran, and `resolveName()` falls back to
     * the warehouse's own name — so a consumption line would quietly start
     * naming a godown Tally does not have. A table that cannot be narrowed is
     * therefore counted whole, exactly as before.
     */
    public function test_a_table_that_records_no_company_at_all_is_counted_as_before(): void
    {
        $this->bindCompany();

        Warehouse::create([
            'code' => 'UNKNOWN',
            'name' => 'SWAASHPET POLYMERS PVT LTD',
            'tally_guid' => 'gd-ours',
            'tally_company' => null,
            'is_active' => true,
        ]);

        $this->assertSame(
            'SWAASHPET POLYMERS PVT LTD',
            app(TallyGodownResolver::class)->soleTallyGodownName(),
        );
    }

    /**
     * The safety half: ONCE the bound company is recorded on a godown, a
     * godown that records nothing is not chosen over it. This is what stops a
     * stale row winning after a pull has said who owns which.
     */
    public function test_once_a_company_is_recorded_a_godown_without_one_cannot_win(): void
    {
        $this->bindCompany();

        Warehouse::create([
            'code' => 'OURS',
            'name' => 'SWAASHPET POLYMERS PVT LTD',
            'tally_guid' => 'gd-ours',
            'tally_company' => self::COMPANY,
            'is_active' => true,
        ]);
        Warehouse::create([
            'code' => 'STALE',
            'name' => 'Some Other Godown',
            'tally_guid' => 'gd-stale',
            'tally_company' => null,
            'is_active' => true,
        ]);

        $this->assertSame(
            'SWAASHPET POLYMERS PVT LTD',
            app(TallyGodownResolver::class)->soleTallyGodownName(),
        );
    }

    /**
     * With no company bound there is nothing to narrow BY, so the seven rows
     * are counted whole and seven is not one. The old rule, unchanged.
     */
    public function test_without_a_bound_company_the_old_count_still_finds_nothing(): void
    {
        $this->twoCompaniesOfGodowns();

        $this->assertNull(app(TallyGodownResolver::class)->soleTallyGodownName());
    }

    /** Two godowns of the BOUND company is still genuinely ambiguous. */
    public function test_two_godowns_of_the_bound_company_stay_ambiguous(): void
    {
        $this->bindCompany();

        foreach (['A', 'B'] as $i => $name) {
            Warehouse::create([
                'code' => 'OURS-'.$i,
                'name' => $name,
                'tally_guid' => 'gd-ours-'.$i,
                'tally_company' => self::COMPANY,
                'is_active' => true,
            ]);
        }

        $this->assertNull(app(TallyGodownResolver::class)->soleTallyGodownName());
    }
}
