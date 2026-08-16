<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\TallySync\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The technical-configuration cleanup (audit §4.8, §7.1): the packaged
 * voucher-granularity default says what live runs, and the inbound surface
 * the agent never called is gone. Nothing here touches factory data — the
 * two granularity modes themselves are covered in ShiftVoucherGranularityTest
 * (shift) and ProductionSyncWritebackTest (batch, pinned explicitly).
 */
class TechnicalConfigCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAgent(array $abilities): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    public function test_the_packaged_voucher_granularity_default_is_shift(): void
    {
        // The packaged default must match the factory's decided mode
        // (DEC-20260807-010) and what live has run since the 07-Aug-2026 flip
        // (DEC-20260807-014). A reader of config/tally-sync.php alone must
        // not be misled about production; 'batch' stays selectable via the
        // env, never the default.
        //
        // Pinned on the SOURCE TEXT, not only the resolved value: env() is
        // evaluated inside the config file, so requiring it would only ever
        // report whatever this machine's .env says. The literal fallback and
        // the .env.example line move together — the example is what a fresh
        // instance (and CI) copies, and an example that named one mode while
        // the fallback named the other would be two defaults.
        $config = file_get_contents(base_path('config/tally-sync.php'));
        $this->assertStringContainsString(
            "env('TALLY_VOUCHER_GRANULARITY', 'shift')",
            $config,
            'config/tally-sync.php must fall back to shift when the env is absent',
        );

        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression(
            '/^TALLY_VOUCHER_GRANULARITY=shift$/m',
            $example,
            '.env.example must set TALLY_VOUCHER_GRANULARITY=shift, in step with the config fallback',
        );

        // The runtime read too — phpunit.xml does not pin the variable, so
        // this is the packaged default on CI. A developer whose local .env
        // pins batch (the documented way to try that mode) will see this one
        // assertion fail; the message says why, so it is not read as a
        // regression in the two source-text checks above.
        $this->assertSame(
            'shift',
            config('tally-sync.voucher_granularity'),
            'tally-sync.voucher_granularity resolved to something other than shift — if TALLY_VOUCHER_GRANULARITY '
            .'is set in your local .env that is expected; the packaged default (checked above) is shift',
        );
    }

    public function test_the_retired_items_endpoint_is_gone(): void
    {
        // The shipped agent only ever posted to /masters; the item-only route
        // was unreachable from any release. Even a token still carrying the
        // retired ability finds nothing there.
        $this->actAsAgent(['tally-sync:items', 'tally-sync:masters']);

        $this->postJson('/api/v1/tally-sync/items', [
            'items' => [['guid' => 'guid-1', 'name' => 'Nope']],
        ])->assertNotFound();

        $this->assertDatabaseCount('items', 0);
    }

    public function test_issued_agent_tokens_carry_no_items_ability(): void
    {
        $token = app(AgentTokenService::class)->issueToken('factory-pc')['token'];

        $this->assertSame(['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters'], $token->abilities);
    }

    public function test_a_legacy_token_still_carrying_the_items_ability_pulls_masters_harmlessly(): void
    {
        // Tokens issued before the ability was dropped are not revoked by
        // this cleanup: the extra string is inert, and the item upsert the
        // old endpoint offered still happens through the masters pull.
        $this->actAsAgent(['tally-sync:poll', 'tally-sync:report', 'tally-sync:items', 'tally-sync:masters']);

        $this->postJson('/api/v1/tally-sync/masters', [
            'items' => [['guid' => 'guid-100ml', 'name' => '100ml', 'base_unit' => 'Nos', 'alter_id' => 12]],
        ])->assertOk()->assertJsonPath('data.items.created', 1);

        $this->assertSame(1, Item::where('tally_stock_item_guid', 'guid-100ml')->count());
    }
}
