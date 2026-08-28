<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A MIRRORED LEDGER CARRIES ITS PARTY DETAILS, so a vendor can be created from
 * it without a person retyping a GSTIN.
 *
 * The mirror held a ledger's name, its group and its Tally id, and nothing
 * else. That was enough for the Settings pick-list it was built for, and it is
 * not enough to make a vendor: the ERP would have to leave GSTIN and state
 * blank on every imported row, or a person would type them back in from a
 * screen they had just exported.
 *
 * WHAT IS AND IS NOT DECIDED HERE. Tally returns what the agent's export
 * REQUEST names, so the agent asks for these fields (masters.ts,
 * exportLedgers). The exact Tally spelling of a ledger's GSTIN and state
 * fields is NOT proven by any export in this repository — the 26-Aug evidence
 * report records that no ledger master export exists — so the agent reads
 * several candidate tags and sends nothing when it finds nothing. That
 * direction matters: a field Tally does not return arrives here as null and
 * the column stays null. NOTHING IS INVENTED, and a wrong guess at a Tally
 * field name costs an empty column, never a wrong GSTIN.
 *
 * The columns are additive and nullable, so every ledger already mirrored
 * keeps the honest answer "not pulled" until the next masters sync.
 */
class LedgerPartyDetailsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAgent(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), ['tally-sync:masters']);
    }

    private function pull(array $ledger): void
    {
        $this->postJson('/api/v1/tally-sync/masters', [
            'company' => 'SWAASHPET POLYMERS PVT LTD Testing',
            'ledger_groups' => [['guid' => 'lg-1', 'name' => 'Sundry Creditors']],
            'ledgers' => [$ledger],
        ])->assertOk();
    }

    public function test_a_pulled_ledger_records_its_gstin_and_state(): void
    {
        $this->actAsAgent();

        $this->pull([
            'guid' => 'led-1',
            'name' => 'Vendor Alpha',
            'group' => 'Sundry Creditors',
            'gstin' => '33AAACD1227B1ZL',
            'state_name' => 'Tamil Nadu',
        ]);

        $ledger = Ledger::where('tally_guid', 'led-1')->firstOrFail();
        $this->assertSame('33AAACD1227B1ZL', $ledger->gstin);
        $this->assertSame('Tamil Nadu', $ledger->state_name);
    }

    /** A re-pull carries the details too, not only the first pull that created the row. */
    public function test_a_re_pull_fills_details_on_a_ledger_that_has_none(): void
    {
        $this->actAsAgent();

        Ledger::create(['tally_guid' => 'led-1', 'name' => 'Vendor Alpha', 'tally_group_name' => 'Sundry Creditors']);

        $this->pull([
            'guid' => 'led-1',
            'name' => 'Vendor Alpha',
            'group' => 'Sundry Creditors',
            'gstin' => '33AAACD1227B1ZL',
            'state_name' => 'Tamil Nadu',
        ]);

        $ledger = Ledger::where('tally_guid', 'led-1')->firstOrFail();
        $this->assertSame('33AAACD1227B1ZL', $ledger->gstin);
        $this->assertSame('Tamil Nadu', $ledger->state_name);
    }

    /**
     * THE DIRECTION THAT MATTERS. A pull that carries no details must leave the
     * details already recorded alone. The agent sends nothing when Tally
     * returns nothing, and a wrong guess at a Tally field name must cost an
     * empty column rather than wipe a GSTIN that is already right.
     */
    public function test_a_pull_without_details_does_not_erase_the_ones_recorded(): void
    {
        $this->actAsAgent();

        $this->pull([
            'guid' => 'led-1',
            'name' => 'Vendor Alpha',
            'group' => 'Sundry Creditors',
            'gstin' => '33AAACD1227B1ZL',
            'state_name' => 'Tamil Nadu',
        ]);

        $this->pull(['guid' => 'led-1', 'name' => 'Vendor Alpha', 'group' => 'Sundry Creditors']);

        $ledger = Ledger::where('tally_guid', 'led-1')->firstOrFail();
        $this->assertSame('33AAACD1227B1ZL', $ledger->gstin, 'a detail-less pull erased a recorded GSTIN');
        $this->assertSame('Tamil Nadu', $ledger->state_name);
    }

    /** A ledger Tally has no GSTIN for is simply blank — never a placeholder. */
    public function test_a_ledger_with_no_gstin_stays_null(): void
    {
        $this->actAsAgent();

        $this->pull(['guid' => 'led-2', 'name' => 'Bank Charges', 'group' => 'Sundry Creditors']);

        $ledger = Ledger::where('tally_guid', 'led-2')->firstOrFail();
        $this->assertNull($ledger->gstin);
        $this->assertNull($ledger->state_name);
    }
}
