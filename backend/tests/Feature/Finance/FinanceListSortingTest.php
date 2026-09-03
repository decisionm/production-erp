<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The chart of accounts and the journal register sort on the SERVER
 * (03-Sep-2026): `sort` is validated at the door, a named column orders
 * the whole set with `id desc` as the tiebreak, and `per_page` pages it
 * honestly with the real total in the meta. Every fixture is synthetic.
 */
class FinanceListSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('finance.view', 'web');
        $user->givePermissionTo('finance.view');
        Sanctum::actingAs($user);
    }

    private function account(string $code, string $name): GLAccount
    {
        return GLAccount::create(['code' => $code, 'name' => $name, 'type' => GLAccountType::Asset, 'is_active' => true]);
    }

    private function entry(string $date): JournalEntry
    {
        return JournalEntry::create(['entry_date' => $date, 'status' => JournalEntryStatus::Draft]);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map(fn (array $row) => $row['id'], $this->getJson($url)->assertOk()->json('data'));
    }

    // ---- gl-accounts ------------------------------------------------------

    public function test_gl_accounts_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/finance/gl-accounts?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/finance/gl-accounts?per_page=1001')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_gl_accounts_sort_descending_on_name_with_newest_id_breaking_the_tie(): void
    {
        $zeta = $this->account('3000', 'Zeta');
        $alphaOld = $this->account('1000', 'Alpha');
        $alphaNew = $this->account('2000', 'Alpha');

        $this->assertSame([$zeta->id, $alphaNew->id, $alphaOld->id], $this->ids('/api/v1/finance/gl-accounts?sort=-name'));
        // The default is still code order.
        $this->assertSame([$alphaOld->id, $alphaNew->id, $zeta->id], $this->ids('/api/v1/finance/gl-accounts'));
    }

    public function test_gl_accounts_page_with_the_real_total(): void
    {
        $this->account('1000', 'A');
        $this->account('2000', 'B');
        $this->account('3000', 'C');

        $this->getJson('/api/v1/finance/gl-accounts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    // ---- journal-entries --------------------------------------------------

    public function test_journal_entries_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/finance/journal-entries?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_journal_entries_sort_descending_on_date_with_newest_id_breaking_the_tie(): void
    {
        $march = $this->entry('2026-03-01');
        $januaryOld = $this->entry('2026-01-05');
        $januaryNew = $this->entry('2026-01-05');

        $this->assertSame([$march->id, $januaryNew->id, $januaryOld->id], $this->ids('/api/v1/finance/journal-entries?sort=-entry_date'));
        $this->assertSame([$januaryNew->id, $januaryOld->id, $march->id], $this->ids('/api/v1/finance/journal-entries?sort=entry_date'));
        // The default is still newest first.
        $this->assertSame([$januaryNew->id, $januaryOld->id, $march->id], $this->ids('/api/v1/finance/journal-entries'));
    }

    public function test_journal_entries_page_with_the_real_total(): void
    {
        $this->entry('2026-01-01');
        $this->entry('2026-01-02');
        $this->entry('2026-01-03');

        $this->getJson('/api/v1/finance/journal-entries?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }
}
