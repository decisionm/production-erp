<?php

namespace Tests\Feature\TallySync\PerType;

use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\TallySync\Models\TallySyncEntry;

/**
 * Journal (finance JE): a balanced draft (POST /finance/journal-entries)
 * POSTED (POST /finance/journal-entries/{id}/post) → JournalEntry::updated
 * → Tally 'Journal'.
 *
 * The Finance module is hidden from the navigation today, and this is
 * exactly why the type is walked here anyway: a JE that IS posted — by an
 * API client, by an old tab — still syncs, and the Control Center must
 * show that row like any other rather than pretend the type does not
 * exist (MASTER-PLAN Phase 3, "visible in the sync page even though Finance
 * nav is hidden").
 *
 * This type's own facts beyond the shared lifecycle:
 *
 *   - DUPLICATE-REFUSED is the post transition (JournalEntryService::post):
 *     a posted entry cannot be posted again, and the model event that
 *     enqueues fires only on the draft → posted change;
 *   - the payload names LEDGERS, not items or a party: no godown, no
 *     party_ledger, nothing for the rate gate to touch (the classifier's
 *     party() and itemSummary() are honestly null).
 */
class JournalLifecycleTest extends PerTypeLifecycleTestCase
{
    private GLAccount $bank;

    private GLAccount $sales;

    private ?int $journalId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = GLAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => GLAccountType::Asset, 'is_active' => true]);
        $this->sales = GLAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => GLAccountType::Revenue, 'is_active' => true]);
    }

    private function accounts(): static
    {
        return $this->asUser($this->staff('Finance Desk', ['finance.view', 'finance.manage']));
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->journalId = $this->accounts()->postJson('/api/v1/finance/journal-entries', [
            'entry_date' => '2026-08-10',
            'reference' => 'JE-REF-9',
            'memo' => 'Cash sale banked',
            'lines' => [
                ['gl_account_id' => $this->bank->id, 'debit' => '100', 'credit' => '0', 'memo' => 'to bank'],
                ['gl_account_id' => $this->sales->id, 'debit' => '0', 'credit' => '100', 'memo' => 'from sales'],
            ],
        ])->assertSuccessful()->json('data.id');

        // A draft is not in anyone's books yet and enqueues nothing …
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A draft journal must not reach the queue');

        // … posting it does.
        $this->accounts()->postJson("/api/v1/finance/journal-entries/{$this->journalId}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'posted');

        return TallySyncEntry::query()->sole();
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        $this->accounts()->postJson("/api/v1/finance/journal-entries/{$this->journalId}/post")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition journal entry from "posted" to "posted".');

        $this->assertSame(1, JournalEntry::query()->count());
    }

    protected function expectedCategoryKey(): string
    {
        return 'journal';
    }

    protected function expectedVoucherType(): string
    {
        return 'Journal';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        // The reference the accountant typed, not JE-{id} — that fallback is
        // for a reference-less entry only.
        return 'JE-REF-9';
    }

    protected function tallyRejection(): string
    {
        return "Ledger '1100 - Bank' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return null;
    }

    public function test_the_payload_names_ledgers_and_the_row_has_no_party_or_item(): void
    {
        $entry = $this->enqueueViaDomain();

        $this->assertSame('2026-08-10', $entry->payload['voucher_date']);
        $this->assertSame('Cash sale banked', $entry->payload['narration']);
        // Line order is the builder's contract and is asserted; key order
        // inside a line is the json column's (MySQL re-orders it) — see
        // Tests\TestCase::assertSameJson.
        $this->assertSameJson(
            [
                ['ledger' => '1100 - Bank', 'debit' => '100.0000', 'credit' => '0.0000', 'memo' => 'to bank'],
                ['ledger' => '4000 - Sales', 'debit' => '0.0000', 'credit' => '100.0000', 'memo' => 'from sales'],
            ],
            $entry->payload['lines'],
        );
        $this->assertArrayNotHasKey('party_ledger', $entry->payload);
        $this->assertArrayNotHasKey('godown', $entry->payload);

        // The list's derived columns say what a journal is: dated, numbered,
        // no party, no item summary — and Finance's hidden nav notwithstanding,
        // it is on the page.
        $row = $this->listedRow($entry->id);
        $this->assertSame('2026-08-10', $row['business_date']);
        $this->assertSame('JE-REF-9', $row['document_number']);
        $this->assertNull($row['party']);
        $this->assertNull($row['item_summary']);
        $this->assertSame('journal', $row['category']['key']);
        // A ledger line carries debit/credit — money on the same resource
        // as a Receipt Note's rate/amount, and gated by the same one rule
        // (FC-06): a viewer without finance.* sees the ledger and the memo,
        // never the figures — keys OMITTED, not nulled.
        $this->assertSameJson(
            [['ledger' => '1100 - Bank', 'memo' => 'to bank'], ['ledger' => '4000 - Sales', 'memo' => 'from sales']],
            $row['payload']['lines'],
        );
        // The agent builds the voucher from them, so it receives them whole.
        $agentRow = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertSame($entry->payload['lines'], $agentRow['payload']['lines']);
    }
}
