<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\TallySync\Models\Ledger;
use App\Support\Tally\TallyText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ONE BAD FIELD ON ONE LEDGER MUST NOT STOP THE FACTORY'S MASTERS SYNC.
 *
 * Written from a live outage on 31-Aug-2026. `ledgers.*.gstin` was validated
 * `max:15` — the column's exact width — and three of this factory's 1742
 * ledgers carry a perfectly good GSTIN with Tally's `&#13;&#10;` on the end,
 * 25 characters. Validation is all-or-nothing, so the request 422'd and the
 * WHOLE pull died with it: 1741 innocent ledgers, 624 items, every godown and
 * ledger group. The agent had just been upgraded to the version that sends
 * these fields at all, which is why a rule written days earlier failed on its
 * first real contact with the books.
 *
 * The bytes below are the real shape (`&#13;&#10;`, literal, undecoded by
 * fast-xml-parser); the GSTINs and names are synthetic (FC-06).
 */
class MastersPullDoesNotFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /** A valid 15-character GSTIN with Tally's line break stuck to it. */
    private const DIRTY_GSTIN = '33AAAAA0000A1ZA&#13;&#10;';

    private function actAsAgent(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), ['tally-sync:masters']);
    }

    private function pull(array $ledgers, array $extra = [])
    {
        return $this->postJson('/api/v1/tally-sync/masters', [
            'company' => 'SYNTHETIC POLYMERS PVT LTD',
            'ledger_groups' => [['guid' => 'lg-1', 'name' => 'Sundry Creditors']],
            'ledgers' => $ledgers,
            ...$extra,
        ]);
    }

    private function ledger(string $guid, string $name, array $overrides = []): array
    {
        return ['guid' => $guid, 'name' => $name, 'group' => 'Sundry Creditors', ...$overrides];
    }

    public function test_a_ledger_whose_gstin_carries_tallys_line_break_no_longer_rejects_the_whole_pull(): void
    {
        $this->actAsAgent();

        $this->pull([
            $this->ledger('led-1', 'Alpha', ['gstin' => self::DIRTY_GSTIN]),
            $this->ledger('led-2', 'Beta', ['gstin' => '27BBBBB1111B1ZB']),
            $this->ledger('led-3', 'Gamma'),
        ])->assertOk();

        // Every ledger landed — the point of the fix.
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], Ledger::orderBy('name')->pluck('name')->all());
    }

    public function test_the_real_gstin_is_recovered_from_it_rather_than_discarded(): void
    {
        $this->actAsAgent();

        $this->pull([$this->ledger('led-1', 'Alpha', ['gstin' => self::DIRTY_GSTIN])])->assertOk();

        // Decoded, not dropped: it IS the factory's GSTIN with rubbish on the
        // end, and throwing it away would lose a fact the books hold.
        $this->assertSame('33AAAAA0000A1ZA', Ledger::where('tally_guid', 'led-1')->value('gstin'));
    }

    public function test_the_rest_of_the_pull_still_lands_when_one_field_is_unusable(): void
    {
        $this->actAsAgent();

        // Two GSTINs typed into one field is NOT a GSTIN with extra on the
        // end — nothing can be recovered, so that one field goes null.
        $this->pull(
            [$this->ledger('led-1', 'Alpha', ['gstin' => '33AAAAA0000A1ZA / 27BBBBB1111B1ZB', 'phone' => '0400000000'])],
            ['items' => [['guid' => 'stk-1', 'name' => 'ITEM_A', 'base_unit' => 'Kgs.']]],
        )->assertOk();

        $ledger = Ledger::where('tally_guid', 'led-1')->first();

        $this->assertNull($ledger->gstin);
        // The ledger itself, its other fields, and the items alongside it all
        // survive. Only the one unusable field is missing.
        $this->assertSame('Alpha', $ledger->name);
        $this->assertSame('0400000000', $ledger->phone);
        $this->assertSame(1, Item::count());
    }

    public function test_a_truncation_is_never_invented_to_make_a_value_fit(): void
    {
        // Both values below are longer than their COLUMN and shorter than the
        // request ceiling — which is precisely the band the outage lived in.
        // The first draft of the fix still 422'd on the phone here.
        $this->actAsAgent();

        $this->pull([$this->ledger('led-1', 'Alpha', [
            // 16 characters: one too many, and not recoverable.
            'gstin' => '33AAAAA0000A1ZAX',
            'phone' => str_repeat('9', 400),
        ])])->assertOk();

        $ledger = Ledger::where('tally_guid', 'led-1')->first();

        // A shortened GSTIN would identify somebody else; a shortened phone
        // number rings somebody else. Null is the only honest answer.
        $this->assertNull($ledger->gstin);
        $this->assertNull($ledger->phone);
    }

    public function test_input_far_too_large_to_be_an_honest_mistake_is_still_refused(): void
    {
        $this->actAsAgent();

        // The ceiling did not disappear, it moved an order of magnitude off
        // the column width. Five thousand characters in a GSTIN field is not a
        // factory typo, and bounding the payload is still worth doing.
        $this->pull([$this->ledger('led-1', 'Alpha', ['gstin' => str_repeat('X', 5000)])])
            ->assertStatus(422);
    }

    public function test_tally_control_markers_are_stripped_from_ordinary_values(): void
    {
        $this->actAsAgent();

        // Tally prefixes its reserved words with &#4;. Same class of artefact.
        $this->pull([$this->ledger('led-1', 'Alpha', ['state_name' => '&#4; Puducherry'])])->assertOk();

        $this->assertSame('Puducherry', Ledger::where('tally_guid', 'led-1')->value('state_name'));
    }

    /* ── The normaliser itself ────────────────────────────────────────── */

    public function test_tally_text_decodes_numeric_references_and_drops_control_characters(): void
    {
        $this->assertSame('33AAAAA0000A1ZA', TallyText::clean(self::DIRTY_GSTIN));
        $this->assertSame('Puducherry', TallyText::clean('&#4; Puducherry'));
        // Hex form too.
        $this->assertSame('AB', TallyText::clean('A&#xD;B'));
        // A real control character, already decoded, is still stripped.
        $this->assertSame('AB', TallyText::clean("A\r\nB"));
        // Blank is not a value.
        $this->assertNull(TallyText::clean('   '));
        $this->assertNull(TallyText::clean('&#13;&#10;'));
        $this->assertNull(TallyText::clean(null));
    }

    public function test_tally_text_never_invents_a_gstin(): void
    {
        $this->assertSame('33AAAAA0000A1ZA', TallyText::gstin(self::DIRTY_GSTIN));
        $this->assertNull(TallyText::gstin('33AAAAA0000A1ZAX'));
        $this->assertNull(TallyText::gstin('short'));
        $this->assertNull(TallyText::gstin(null));
    }
}
