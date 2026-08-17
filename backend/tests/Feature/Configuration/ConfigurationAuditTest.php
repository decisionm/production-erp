<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE AUDIT COLUMN, and its blast radius.
 *
 * `spatie/laravel-activitylog` shipped installed, migrated and wired to
 * nothing. Turning it on is cheap; turning it on for the WRONG rows is not —
 * an append-only ledger that starts narrating itself into a second table, or
 * a Tally masters pull that mints 644 rows every cycle, are both worse than
 * no audit at all. So every test here is paired: one proves the trail EXISTS
 * for a configuration edit, the next proves it does NOT exist anywhere it was
 * not asked for.
 */
class ConfigurationAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Tier-1 configuration models this pass audits — the ten the factory
     * touches daily. Anything outside this list must stay silent.
     *
     * @var array<class-string, string>
     */
    private const TIER_ONE = [
        Item::class => 'items',
        Warehouse::class => 'warehouses',
        WorkCenter::class => 'work_centers',
        Shift::class => 'shifts',
        ScrapReason::class => 'scrap_reasons',
        Mold::class => 'molds',
        ProductionStandard::class => 'production_standards',
        ProductionConfiguration::class => 'production_configurations',
        DowntimeReason::class => 'downtime_reasons',
        Employee::class => 'employees',
    ];

    public function test_creating_a_configuration_record_logs_the_causer_and_the_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $rows = DB::table('activity_log')->get();
        $this->assertCount(1, $rows, 'one configuration create must leave exactly one audit row');

        $row = $rows->first();
        $this->assertSame('configuration', $row->log_name);
        $this->assertSame('created', $row->event);
        $this->assertSame('item.created', $row->description);
        $this->assertSame(Item::class, $row->subject_type);
        $this->assertSame($item->id, (int) $row->subject_id);
        $this->assertSame(User::class, $row->causer_type);
        $this->assertSame($user->id, (int) $row->causer_id);

        $changes = json_decode($row->attribute_changes, true);
        $this->assertSame('Bottle', $changes['attributes']['name']);
        $this->assertSame('FG-1', $changes['attributes']['sku']);

        $item->refresh();
        $this->assertSame($user->id, $item->created_by);
        $this->assertSame($user->id, $item->updated_by);
    }

    public function test_editing_logs_only_what_changed_with_its_before_and_after(): void
    {
        $author = User::factory()->create();
        Sanctum::actingAs($author);
        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);

        $editor = User::factory()->create();
        Sanctum::actingAs($editor);
        // Re-read the row the way a request does (route-model binding hands
        // the service a hydrated model): the "before" side of the trail is
        // the row as the database holds it, columns with database defaults
        // included.
        $item = Item::findOrFail($item->id);
        $item->update(['name' => 'Bottle 500ml']);

        $row = DB::table('activity_log')->where('event', 'updated')->first();
        $this->assertNotNull($row, 'an edit to a configuration record must be audited');
        $this->assertSame('configuration', $row->log_name);
        $this->assertSame('item.updated', $row->description);
        $this->assertSame($editor->id, (int) $row->causer_id);

        $changes = json_decode($row->attribute_changes, true);
        $this->assertSame(['name' => 'Bottle 500ml'], $changes['attributes'], 'logOnlyDirty: untouched columns must not be logged');
        $this->assertSame(['name' => 'Bottle'], $changes['old']);

        $item->refresh();
        $this->assertSame($author->id, $item->created_by, 'created_by records the author, never the last editor');
        $this->assertSame($editor->id, $item->updated_by);
    }

    public function test_a_transaction_row_is_never_audited(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Main']);
        DB::table('activity_log')->delete();

        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'receipt',
            'quantity' => 10,
            'movement_date' => now(),
        ]);

        $this->assertSame(0, DB::table('activity_log')->count(), 'the stock ledger is append-only and audits itself — it must not be mirrored into activity_log');
    }

    public function test_a_configuration_model_outside_this_pass_is_not_audited(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        DB::table('activity_log')->delete();

        Bom::create(['item_id' => $item->id, 'name' => 'Recipe', 'version' => '1', 'is_active' => true]);

        $this->assertSame(0, DB::table('activity_log')->count(), 'the trait is applied model by model — a Tier-2 master must stay untouched until its own pass');
    }

    public function test_every_audit_row_written_by_a_mixed_workload_is_a_tier_one_configuration_subject(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Main']);
        $scrapReason = ScrapReason::create(['code' => 'SR-1', 'name' => 'Flash']);
        $scrapReason->update(['name' => 'Flashing']);
        Bom::create(['item_id' => $item->id, 'name' => 'Recipe', 'version' => '1', 'is_active' => true]);
        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'receipt',
            'quantity' => 10,
            'movement_date' => now(),
        ]);

        $subjects = DB::table('activity_log')->pluck('subject_type')->unique()->values()->all();
        sort($subjects);

        $this->assertSame([Item::class, Warehouse::class, ScrapReason::class], $subjects);
        $this->assertSame(
            [],
            DB::table('activity_log')->where('log_name', '!=', 'configuration')->pluck('log_name')->all(),
            'every row this phase writes belongs to the configuration log'
        );
    }

    public function test_a_tally_masters_re_pull_writes_no_audit_row(): void
    {
        $service = app(ItemService::class);

        Carbon::setTestNow('2026-08-17 10:00:00');
        $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle', 'base_unit' => 'Nos', 'alter_id' => 7]);
        $syncedBefore = DB::table('items')->where('tally_stock_item_guid', 'guid-1')->value('tally_synced_at');
        DB::table('activity_log')->delete();

        // The same masters row, pulled again five minutes later: only
        // tally_synced_at moves. 644 items x every pull is the difference
        // between an audit trail and a landfill.
        Carbon::setTestNow('2026-08-17 10:05:00');
        $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle', 'base_unit' => 'Nos', 'alter_id' => 7]);

        // The load-bearing half: the pull really did write the row, so a zero
        // count below can only be the suppression and never "nothing
        // happened". Without the clock moved, both pulls land in the same
        // second, tally_synced_at is not dirty, Eloquent skips the UPDATE
        // entirely and this test would pass while proving nothing.
        $this->assertNotSame(
            $syncedBefore,
            DB::table('items')->where('tally_stock_item_guid', 'guid-1')->value('tally_synced_at'),
            'the re-pull must actually have written the row'
        );
        $this->assertSame(0, DB::table('activity_log')->count(), 'a sync-bookkeeping touch is not an edit anybody audits');

        Carbon::setTestNow();
    }

    public function test_a_tally_rename_is_still_audited(): void
    {
        $service = app(ItemService::class);
        $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle', 'base_unit' => 'Nos', 'alter_id' => 7]);
        DB::table('activity_log')->delete();

        $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle 500ml', 'base_unit' => 'Nos', 'alter_id' => 8]);

        $row = DB::table('activity_log')->where('event', 'updated')->first();
        $this->assertNotNull($row, 'a name arriving from Tally is a real change to a master and must be recorded');
        $this->assertNull($row->causer_id, 'the pull runs unauthenticated — the trail must say so rather than blame a person');

        $changes = json_decode($row->attribute_changes, true);
        $this->assertSame('Bottle 500ml', $changes['attributes']['name']);
    }

    public function test_an_unauthenticated_writer_never_erases_a_persons_stamp(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $service = app(ItemService::class);
        $item = $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle', 'base_unit' => 'Nos'])['item'];

        // Nobody behind the wheel: the masters pull runs from the agent, not
        // from a session.
        $this->app['auth']->forgetGuards();

        $service->upsertFromTally(['guid' => 'guid-1', 'name' => 'Bottle 500ml', 'base_unit' => 'Nos']);

        $item->refresh();
        $this->assertSame($user->id, $item->created_by);
        $this->assertSame($user->id, $item->updated_by, 'a pull with nobody behind it must leave the last person who edited the row standing');
    }

    public function test_every_tier_one_model_records_configuration_audit(): void
    {
        foreach (array_keys(self::TIER_ONE) as $model) {
            $this->assertContains(
                RecordsConfigurationAudit::class,
                class_uses_recursive($model),
                "{$model} is Tier-1 configuration and must carry the audit concern"
            );
        }
    }

    public function test_the_stamps_migration_reverses_without_destroying_a_column_it_never_added(): void
    {
        // production_standards.created_by predates this phase — the services
        // set it explicitly — so a rollback must leave it standing.
        $migration = require database_path('migrations/2026_08_17_090000_add_audit_stamps_to_configuration_tables.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('items', 'created_by'));
        $this->assertFalse(Schema::hasColumn('items', 'updated_by'));
        $this->assertFalse(Schema::hasColumn('production_standards', 'updated_by'));
        $this->assertTrue(
            Schema::hasColumn('production_standards', 'created_by'),
            'the rollback dropped a column this migration never added'
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('items', 'created_by'));
        $this->assertTrue(Schema::hasColumn('items', 'updated_by'));
        $this->assertTrue(Schema::hasColumn('production_standards', 'updated_by'));
        $this->assertTrue(Schema::hasColumn('production_standards', 'created_by'));
    }

    public function test_every_tier_one_table_carries_both_audit_stamps(): void
    {
        foreach (self::TIER_ONE as $model => $table) {
            foreach (['created_by', 'updated_by'] as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} is missing — {$model} cannot stamp who wrote the row"
                );
            }
        }
    }
}
