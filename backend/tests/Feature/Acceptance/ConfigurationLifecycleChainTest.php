<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Support\Configuration\ConfigurationInUseException;
use App\Support\Configuration\ConfigurationLifecycle;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\SchemaCascades;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PHASE 8 · CHAIN D — THE CONFIGURATION LIFECYCLE (Phase 7.6,
 * DEC-20260817-002).
 *
 *   Create → View → Edit → Deactivate (while referenced) → excluded from
 *   NEW work while HISTORY still renders it → Reactivate → a duplicate
 *   active code refused → a referenced record's delete REFUSED with counts
 *   → an unused record genuinely deleted → the audit trail → fail-closed
 *   where past use cannot be proven
 *
 * THE THREAD THAT MAKES THIS ONE WALK RATHER THAN NINE ASSERTIONS: a
 * master's business code stays RESERVED for as long as its row exists —
 * through deactivation and through archiving — and is released only once
 * the row is genuinely gone (DEC-20260817-002 §§1-2); and the audit trail
 * of that master OUTLIVES the row it describes. Everything below hangs off
 * those two facts.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT LAYER THIS WALKS, AND WHY IT IS NOT THE ROUTE LAYER THROUGHOUT
 * ─────────────────────────────────────────────────────────────────────────
 * Phase 7.6 shipped the MECHANISM (`App\Support\Configuration\*`) and the
 * live-facing active-flag filters. It did NOT wire a single entity to the
 * contract's routes: `grep -r ManagesConfigurationLifecycle app/Modules`
 * returns nothing, no master has an archive / activate / destroy endpoint of
 * the contract's shape (the one `…/configurations/{id}/deactivate` route
 * predates 7.6 and writes its own status column — see the last test), and no
 * Resource serves the `can` block the shared frontend is built to read. So
 * this chain walks each link at the layer that ACTUALLY EXISTS and
 * says which layer that was, rather than inventing a wired entity or a
 * route that is not there:
 *
 *   through the REAL ROUTES        create, view, edit, duplicate-code
 *                                  refusal, exclusion from new operational
 *                                  selection, history still rendering;
 *   through the SERVICE MECHANISM  deactivate, reactivate, the refusal with
 *                                  counts, the genuine delete, the
 *                                  fail-closed verdicts, the schema
 *                                  backstop;
 *   NOT WALKED AT ALL              an entity's own lifecycle endpoints and
 *                                  its `can` block — they do not exist.
 *                                  Recorded NOT TESTED, never BLOCKED:
 *                                  "the wiring wave has not shipped" is an
 *                                  implementation gap, not an owner gate.
 *
 * THE DEPENDENCY DECLARATION BELOW IS AUTHORED BY THIS TEST, because no
 * module declares one yet. It is the smallest declaration the schema
 * backstop accepts for `warehouses` (see `warehouseChecks()`), so the walk
 * is honest about the mechanism and says nothing about a declaration
 * Inventory has not written. Likewise the hard-delete authority callback:
 * DEC-20260817-002 §3 reserves the hard delete to Super Admin / Owner, this
 * repo has no such role, and the shipped default therefore deletes NOTHING
 * (walked as its own link, D8a).
 *
 * FIXTURES are prefixed `ACC-CFG-` and every number is an arbitrary test
 * constant chosen so the arithmetic can be checked by hand. None of them is
 * a measurement of anything in SWAASHPET POLYMERS, and nothing here may be
 * quoted as a factory value. The walk runs against the test database only:
 * no live read, no live write, no Tally connection, no purchase-order Tally
 * flag touched.
 *
 * TWO GAPS THIS WALK RECORDS RATHER THAN HIDES (see D11):
 *   - the schema backstop reads ON DELETE CASCADE only, so a SET NULL child
 *     is silently re-pointed by a delete the report called clear;
 *   - a RESTRICT child is outside the declaration too, so its refusal
 *     arrives as a database FK error rather than the contract's 422 with
 *     counts.
 */
class ConfigurationLifecycleChainTest extends TestCase
{
    use RefreshDatabase;

    /** Arbitrary test constants — not factory figures. */
    private const RECEIPT_QTY = '40.0000';

    private const RECEIPT_COST = '11.0000';

    private Item $resin;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        SchemaCascades::flush();

        $this->author = $this->actingAsConfigurationUser('ACC-CFG Author');

        $this->resin = Item::create([
            'sku' => 'ACC-CFG-RES', 'name' => 'ACC_CFG_RESIN', 'uom' => 'Kgs', 'is_active' => true,
        ]);

        // The item's own audit rows are not this chain's subject; every
        // assertion below scopes the trail to Warehouse subjects.
    }

    // ---- actors --------------------------------------------------------------

    private function actingAsConfigurationUser(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);

        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    // ---- the declaration under test ------------------------------------------

    /**
     * Everything the SCHEMA cascades out of `warehouses` — the whole of it,
     * which is what makes the report's "clear" verdict mean something. The
     * cascade backstop is asked for this list in D10 rather than trusted.
     *
     * @return list<DependencyCheck>
     */
    private function warehouseChecks(): array
    {
        return [
            DependencyCheck::table('stock_balances', 'warehouse_id')
                ->label('stock balance')
                ->cascadeSide(),
        ];
    }

    /**
     * @param  list<DependencyCheck>|null  $checks
     */
    private function lifecycle(?array $checks = null, bool $mayHardDelete = true): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'warehouse',
            checks: $checks ?? $this->warehouseChecks(),
            canHardDelete: $mayHardDelete
                // Test-supplied: DEC-20260817-002 §3's Super Admin does not
                // exist in this repo, and the shipped default is D8a's refusal.
                ? fn (?Authenticatable $user): bool => true
                : null,
        );
    }

    // ---- helpers -------------------------------------------------------------

    /** Create a warehouse the way a person does — through its own route. */
    private function createWarehouseThroughTheRoute(string $code, string $name): Warehouse
    {
        $response = $this->postJson('/api/v1/inventory/warehouses', ['code' => $code, 'name' => $name]);
        $response->assertCreated();

        return Warehouse::findOrFail($response->json('data.id'));
    }

    private function receiptInto(Warehouse $warehouse): TestResponse
    {
        return $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->resin->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => self::RECEIPT_QTY,
            'unit_cost' => self::RECEIPT_COST,
            'reference' => 'ACC-CFG-RCPT',
        ]);
    }

    /** @return list<array{event: string, description: string, causer: int|null}> */
    private function warehouseTrail(int $warehouseId): array
    {
        return DB::table('activity_log')
            ->where('subject_type', Warehouse::class)
            ->where('subject_id', $warehouseId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => [
                'event' => $row->event,
                'description' => $row->description,
                'causer' => $row->causer_id === null ? null : (int) $row->causer_id,
            ])
            ->all();
    }

    // =========================================================================
    // D1 · D2 · D3 — create, view, edit, and a duplicate active code refused
    // =========================================================================

    public function test_d1_to_d3_a_master_is_created_viewed_and_edited_through_its_own_routes(): void
    {
        $author = $this->author;

        // D1 — CREATE, through the wired route.
        $created = $this->postJson('/api/v1/inventory/warehouses', [
            'code' => 'ACC-CFG-STORE',
            'name' => 'ACC Configuration Store',
        ]);
        $created->assertCreated();

        $warehouse = Warehouse::findOrFail($created->json('data.id'));
        $this->assertSame('ACC-CFG-STORE', $warehouse->code);
        $this->assertTrue($warehouse->is_active, 'a new master is in service');
        $this->assertSame($author->id, $warehouse->created_by);
        $this->assertSame($author->id, $warehouse->updated_by);

        $this->assertSame(
            [['event' => 'created', 'description' => 'warehouse.created', 'causer' => $author->id]],
            $this->warehouseTrail($warehouse->id),
            'the create is audited under the configuration log with its causer',
        );

        // D2a — VIEW: the master reads back on its own index.
        $index = $this->getJson('/api/v1/inventory/warehouses');
        $index->assertOk();
        $this->assertContains(
            'ACC-CFG-STORE',
            array_column($index->json('data'), 'code'),
            'a created master is visible on the list it belongs to',
        );

        // D3 — a DUPLICATE ACTIVE CODE is refused. The code identifies the
        // master to Tally and to the floor; two live rows may not share one.
        $duplicate = $this->postJson('/api/v1/inventory/warehouses', [
            'code' => 'ACC-CFG-STORE',
            'name' => 'ACC Configuration Store (second)',
        ]);
        $duplicate->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(1, Warehouse::withTrashed()->where('code', 'ACC-CFG-STORE')->count());

        // D2b — EDIT, by a DIFFERENT person, so the trail can be shown to
        // record who, not merely that.
        $editor = $this->actingAsConfigurationUser('ACC-CFG Editor');
        $edited = $this->putJson("/api/v1/inventory/warehouses/{$warehouse->id}", [
            'name' => 'ACC Configuration Store (renamed)',
        ]);
        $edited->assertOk();

        $warehouse->refresh();
        $this->assertSame('ACC Configuration Store (renamed)', $warehouse->name);
        $this->assertSame($author->id, $warehouse->created_by, 'created_by records the author, never the last editor');
        $this->assertSame($editor->id, $warehouse->updated_by);

        $trail = $this->warehouseTrail($warehouse->id);
        $this->assertCount(2, $trail);
        $this->assertSame(['event' => 'updated', 'description' => 'warehouse.updated', 'causer' => $editor->id], $trail[1]);

        $update = DB::table('activity_log')
            ->where('subject_type', Warehouse::class)
            ->where('event', 'updated')
            ->orderByDesc('id')
            ->first();
        $changes = json_decode($update->attribute_changes, true);
        $this->assertSame(['name' => 'ACC Configuration Store (renamed)'], $changes['attributes'], 'only what changed is logged');
        $this->assertSame(['name' => 'ACC Configuration Store'], $changes['old'], 'with its before value');
    }

    // =========================================================================
    // D4 · D5 · D6 — deactivate while referenced, excluded from NEW work while
    // HISTORY still renders it, reactivate
    // =========================================================================

    public function test_d4_to_d6_a_referenced_master_is_deactivated_excluded_from_new_work_still_rendered_and_reactivated(): void
    {
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-STORE', 'ACC Configuration Store');

        // A Tally identity, written at the database level so the archive can
        // be shown to leave it alone without an edit of its own in the trail.
        DB::table('warehouses')->where('id', $warehouse->id)->update(['tally_guid' => 'acc-cfg-guid-store']);
        $warehouse->refresh();

        // The master is put to WORK — this is what "while referenced" means.
        $this->receiptInto($warehouse)->assertCreated();
        $movementId = StockMovement::query()->where('warehouse_id', $warehouse->id)->sole()->id;
        $this->assertSame(1, StockBalance::query()->where('warehouse_id', $warehouse->id)->count());

        // ---- D4 — DEACTIVATE WHILE REFERENCED -------------------------------
        // It is allowed, it is reversible, and it destroys nothing. Archive is
        // the answer the contract offers instead of a delete.
        $lifecycle = $this->lifecycle();
        $this->assertTrue($lifecycle->abilities($warehouse, resolveDelete: false)['archive']);

        $lifecycle->archive($warehouse, reason: 'ACC-CFG walk');
        $warehouse->refresh();

        $this->assertFalse($warehouse->is_active, 'archive takes the master out of service');
        $this->assertFalse($warehouse->trashed(), 'a master with an active flag is deactivated, not soft-deleted');
        $this->assertSame(1, StockBalance::query()->where('warehouse_id', $warehouse->id)->count(), 'archive destroys nothing');
        $this->assertNotNull(StockMovement::query()->find($movementId), 'archive moves no stock');
        $this->assertSame('acc-cfg-guid-store', $warehouse->tally_guid, 'archive touches no Tally field');
        $this->assertSame(0, TallySyncEntry::query()->count(), 'DEC-20260817-002 §4: archiving a master queues no Tally mutation');

        // The mechanism enforces the ability it just published: an already
        // retired master has nothing left to archive.
        $this->assertFalse($lifecycle->abilities($warehouse, resolveDelete: false)['archive']);
        try {
            $lifecycle->archive($warehouse);
            $this->fail('an already-retired master must not archive twice');
        } catch (LogicException $e) {
            $this->assertSame('This warehouse is already retired, so there is nothing to archive.', $e->getMessage());
        }

        // ---- D5 — EXCLUDED FROM NEW WORK, STILL RENDERED ON HISTORY ---------
        $refused = $this->receiptInto($warehouse);
        $refused->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(1, StockMovement::query()->where('warehouse_id', $warehouse->id)->count(), 'the refusal recorded nothing');

        $history = $this->getJson("/api/v1/inventory/stock-movements?warehouse_id={$warehouse->id}");
        $history->assertOk();
        $rows = $history->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($movementId, $rows[0]['id']);
        $this->assertSame('ACC-CFG-STORE', $rows[0]['warehouse']['code'], 'history still names the deactivated master');
        $this->assertFalse($rows[0]['warehouse']['is_active'], 'and says plainly that it is out of service');

        // ---- D6 — REACTIVATE ------------------------------------------------
        $this->assertTrue($lifecycle->abilities($warehouse, resolveDelete: false)['activate']);
        $lifecycle->activate($warehouse, reason: 'ACC-CFG walk');
        $warehouse->refresh();
        $this->assertTrue($warehouse->is_active);

        // The falsifier for D5: the refusal was the flag and nothing else.
        $this->receiptInto($warehouse)->assertCreated();
        $this->assertSame(2, StockMovement::query()->where('warehouse_id', $warehouse->id)->count());

        $this->assertFalse(
            $lifecycle->abilities($warehouse, resolveDelete: false)['activate'],
            'an in-service master has nothing to reactivate',
        );
    }

    // =========================================================================
    // D7 — the delete of a REFERENCED record is refused, with counts
    // =========================================================================

    public function test_d7_a_referenced_masters_delete_is_refused_with_integer_counts_and_its_children_survive(): void
    {
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-STORE', 'ACC Configuration Store');
        $this->receiptInto($warehouse)->assertCreated();

        $lifecycle = $this->lifecycle();

        // The advisory answer the UI would render.
        $report = $lifecycle->report($warehouse);
        $this->assertFalse($report->isClear());
        $this->assertFalse($lifecycle->abilities($warehouse)['delete'], 'a referenced master is not offered a delete');

        try {
            $lifecycle->delete($warehouse);
            $this->fail('a referenced master must not be deleted');
        } catch (ConfigurationInUseException $e) {
            $payload = $e->payload();

            $this->assertSame('configuration_in_use', $e->errorCode());
            $this->assertSame(
                'Cannot delete warehouse "ACC Configuration Store" — used by 1 stock balance. Deactivate instead.',
                $e->getMessage(),
            );
            $this->assertSame('archive', $payload['alternative'], 'the refusal offers the reversible half instead');

            $this->assertCount(1, $payload['blocking']);
            $this->assertSame('stock_balances', $payload['blocking'][0]['code']);
            $this->assertSame('stock balance', $payload['blocking'][0]['label']);
            $this->assertIsInt($payload['blocking'][0]['count'], 'the count is a number the UI can print, not prose');
            $this->assertSame(1, $payload['blocking'][0]['count']);
            $this->assertSame([], $payload['unprovable']);
            $this->assertSame([], $payload['cascade_gaps']);
        }

        // Refused means refused: the master survives and so does every child
        // the database would have cascaded away.
        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));
        $this->assertSame(1, StockBalance::query()->where('warehouse_id', $warehouse->id)->count());
        $this->assertSame(1, StockMovement::query()->where('warehouse_id', $warehouse->id)->count());
    }

    // =========================================================================
    // D8 — the code stays reserved while the row exists, and is released only
    // when the row is genuinely gone; the audit trail outlives the record
    // =========================================================================

    public function test_d8_an_unused_master_is_really_deleted_and_only_then_is_its_code_released(): void
    {
        $author = $this->author;
        $spare = $this->createWarehouseThroughTheRoute('ACC-CFG-SPARE', 'ACC Configuration Spare');

        $lifecycle = $this->lifecycle();

        // Deactivated: still a row, so still the owner of its code.
        $lifecycle->archive($spare);
        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'ACC-CFG-SPARE', 'name' => 'ACC Reuse'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // Archived by soft delete: still a row, still the owner of its code —
        // which is exactly why the repo's code uniqueness counts trashed rows.
        $spare->delete();
        $this->assertTrue($spare->fresh()?->trashed() ?? false);
        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'ACC-CFG-SPARE', 'name' => 'ACC Reuse'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // A trashed master is answered like any other: being archived does not
        // make it used, and the delete finds the same row.
        $trashed = Warehouse::withTrashed()->findOrFail($spare->id);
        $this->assertTrue($lifecycle->report($trashed)->isClear());
        $this->assertTrue($lifecycle->abilities($trashed)['delete']);

        $lifecycle->delete($trashed);

        // The delete is REAL — not a second soft delete.
        $this->assertNull(Warehouse::withTrashed()->find($spare->id), 'a proven-unused master is destroyed outright');
        $this->assertSame(0, DB::table('warehouses')->where('id', $spare->id)->count());

        // ...and only now is the code free.
        $reused = $this->postJson('/api/v1/inventory/warehouses', ['code' => 'ACC-CFG-SPARE', 'name' => 'ACC Reuse']);
        $reused->assertCreated();
        $this->assertNotSame($spare->id, $reused->json('data.id'), 'a new row, not the old one restored');

        // The trail outlives the row it describes — the only thing left that
        // says the master ever existed.
        $trail = $this->warehouseTrail($spare->id);
        $this->assertSame(
            ['created', 'updated', 'deleted', 'deleted'],
            array_column($trail, 'event'),
            // AND AN HONEST LIMIT OF THE TRAIL: the deactivation reads as an
            // ordinary `warehouse.updated` (its is_active before/after is the
            // only thing distinguishing it), and the archive-by-soft-delete
            // and the hard delete are BOTH `warehouse.deleted`. The trail
            // records that something happened and who did it; it does not yet
            // name the lifecycle action.
            'created, deactivated, archived-by-soft-delete, destroyed',
        );
        $this->assertSame([$author->id], array_values(array_unique(array_column($trail, 'causer'))));
        $this->assertSame(
            0,
            DB::table('activity_log')->where('log_name', '!=', 'configuration')->count(),
            'every row this walk wrote belongs to the configuration trail',
        );
    }

    // =========================================================================
    // D8a — the shipped hard-delete authority is FAIL-CLOSED: today nothing
    // can be hard-deleted at all, because nothing declares who may
    // =========================================================================

    public function test_d8a_with_no_declared_authority_the_hard_delete_is_refused_before_a_single_count_is_paid(): void
    {
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-AUTH', 'ACC Configuration Authority');

        // Nothing references it: the ONLY reason a delete can fail here is the
        // authority seam.
        $permissive = $this->lifecycle();
        $this->assertTrue($permissive->report($warehouse)->isClear());

        $shipped = $this->lifecycle(mayHardDelete: false);
        $this->assertFalse(
            $shipped->abilities($warehouse)['delete'],
            'an unauthorised user is answered FALSE — not null: no amount of counting would change it',
        );

        try {
            $shipped->delete($warehouse);
            $this->fail('DEC-20260817-002 §3: with no declared authority, nothing hard-deletes');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('reserved to Super Admin / Owner', $e->getMessage());
            $this->assertStringContainsString('Deactivate instead', $e->getMessage());
        }

        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));
    }

    // =========================================================================
    // D9 — the fail-closed rule: where past use CANNOT be proven, the delete is
    // refused, and no number is invented
    // =========================================================================

    public function test_d9_where_past_use_cannot_be_proven_the_delete_is_refused_and_no_count_is_invented(): void
    {
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-LEGACY', 'ACC Configuration Legacy');

        // Same row, two declarations. With the declaration that can answer
        // every question, the master is provably unused.
        $this->assertTrue($this->lifecycle()->report($warehouse)->isClear());

        // Add one question that CANNOT be answered — history nobody recorded —
        // and the same row becomes undeletable.
        $failClosed = $this->lifecycle([
            ...$this->warehouseChecks(),
            DependencyCheck::unprovable('legacy_stock_history')->label('legacy stock history'),
        ]);

        $report = $failClosed->report($warehouse);
        $this->assertFalse($report->isClear(), 'a question that cannot be answered blocks exactly like a positive count');
        $this->assertSame([], $report->blocking());
        $this->assertSame([['code' => 'legacy_stock_history', 'label' => 'legacy stock history']], $report->unprovable());
        $this->assertArrayNotHasKey(
            'count',
            $report->unprovable()[0],
            'a missing figure is reported missing — never interpolated',
        );

        try {
            $failClosed->delete($warehouse);
            $this->fail('DEC-20260817-002 §5: an unprovable past blocks the delete');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame(
                'Cannot delete warehouse "ACC Configuration Legacy" — past use of legacy stock history cannot be verified. Deactivate instead.',
                $e->getMessage(),
            );
            $this->assertSame([], $e->payload()['blocking'], 'no count is invented for what could not be counted');
            $this->assertSame([['code' => 'legacy_stock_history', 'label' => 'legacy stock history']], $e->payload()['unprovable']);
        }

        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));
    }

    // =========================================================================
    // D10 — the schema cascade backstop: an INCOMPLETE declaration is refused,
    // by name, even though nothing references the record
    // =========================================================================

    public function test_d10_an_incomplete_declaration_is_refused_by_the_schema_cascade_backstop(): void
    {
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-GAP', 'ACC Configuration Gap');

        // The schema's own answer, asked rather than assumed: `stock_balances`
        // is what a real DELETE of a warehouse would destroy.
        $cascades = SchemaCascades::referencing($warehouse->getConnection(), 'warehouses');
        $this->assertNotNull($cascades, 'a driver whose cascades cannot be read must block, not pass');
        $this->assertSame(
            [['table' => 'stock_balances', 'columns' => ['warehouse_id']]],
            $cascades,
            'if this list grows, the declaration below is no longer complete and this chain must be re-walked',
        );

        // Same row, two declarations, opposite answers — so the verdict can
        // only be about the declaration. The complete one is clear...
        $this->assertTrue($this->lifecycle()->report($warehouse)->isClear());

        // ...and the one that forgets a cascading child refuses, naming it.
        $incomplete = $this->lifecycle(checks: []);
        $report = $incomplete->report($warehouse);

        $this->assertFalse($report->isClear());
        $this->assertSame([], $report->blocking(), 'nothing references this row — the refusal is about the declaration');
        $this->assertSame([
            [
                'table' => 'stock_balances',
                'column' => 'warehouse_id',
                'reason' => 'undeclared',
                'message' => 'the schema cascades stock_balances.warehouse_id and no check declares it',
            ],
        ], $report->cascadeGaps());

        try {
            $incomplete->delete($warehouse);
            $this->fail('a cascading child nobody declared must block the delete');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame('stock_balances', $e->payload()['cascade_gaps'][0]['table']);
            $this->assertStringContainsString('no check declares it', $e->getMessage());
        }

        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));

        // The backstop's OTHER refusal — `archived_rows_uncounted`, for a
        // check that skips trashed children of a soft-deleting table — cannot
        // be walked on this master: `stock_balances` keeps no `deleted_at`, so
        // a live-only check and a trashed-counting one are the same cover
        // here, and the report is clear either way. Said plainly rather than
        // simulated; that branch is pinned by
        // tests/Feature/Configuration/ConfigurationCascadeBackstopTest.php on
        // a child that really does soft-delete.
        $ignoresTrashed = $this->lifecycle([
            DependencyCheck::table('stock_balances', 'warehouse_id')->label('stock balance'),
        ]);
        $this->assertFalse(
            DependencyCheck::tableSoftDeletes($warehouse->getConnection()->getName(), 'stock_balances'),
            'if stock_balances gains a deleted_at, the live-only check below stops being a cover and this link changes',
        );
        $this->assertSame([], $ignoresTrashed->report($warehouse)->cascadeGaps());
    }

    // =========================================================================
    // D11 — RECORDED GAP: the backstop reads ON DELETE CASCADE only
    // =========================================================================

    /**
     * A finding, walked rather than asserted from reading: `SchemaCascades`
     * asks the schema for CASCADE foreign keys, so the other two shapes are
     * outside the declaration entirely.
     *
     * `warehouses` is referenced by ten RESTRICT children (stock_movements,
     * goods_receipt_notes, deliveries, work_orders, shift_production_entries,
     * shift_material_consumptions, maintenance_work_order_parts,
     * subcontract_orders, rework_orders, store_issues) and two SET NULL ones
     * (serial_numbers.warehouse_id, material_bags.current_warehouse_id). None
     * of them is reported by the backstop, so a declaration that names only
     * `stock_balances` — the declaration the backstop calls COMPLETE — lets
     * the delete proceed:
     *
     *   SET NULL  the child is silently re-pointed. A serial number loses the
     *             store it was in, and nothing refuses and nothing says so.
     *   RESTRICT  the database refuses, so the row survives — fail-closed —
     *             but the caller gets a driver FK error instead of the
     *             contract's 422 with counts and its "archive instead" offer.
     *
     * This test asserts what the mechanism DOES today. If it starts failing
     * because a later pass taught the backstop to read SET NULL / RESTRICT,
     * that is the gap closing: update this link, do not weaken it.
     */
    public function test_d11_recorded_gap_a_set_null_or_restrict_child_is_outside_the_declaration(): void
    {
        // ---- SET NULL: the delete succeeds and quietly re-points the child --
        $serialStore = $this->createWarehouseThroughTheRoute('ACC-CFG-SERIAL', 'ACC Configuration Serial Store');
        $serial = SerialNumber::create([
            'item_id' => $this->resin->id,
            'serial_number' => 'ACC-CFG-SN-1',
            'warehouse_id' => $serialStore->id,
        ]);

        $lifecycle = $this->lifecycle();
        $this->assertTrue(
            $lifecycle->report($serialStore)->isClear(),
            'RECORDED GAP: a serial number pointing at this store is invisible to the declaration',
        );

        $lifecycle->delete($serialStore);

        $this->assertNull(Warehouse::withTrashed()->find($serialStore->id));
        $this->assertNotNull($serial->fresh(), 'the child row survives...');
        $this->assertNull(
            $serial->fresh()->warehouse_id,
            'RECORDED GAP: ...but its store was silently set to null by a delete the report called clear',
        );

        // ---- RESTRICT: the database refuses, not the contract ---------------
        $restrictStore = $this->createWarehouseThroughTheRoute('ACC-CFG-RESTRICT', 'ACC Configuration Restrict Store');
        // A movement with no balance row of its own — the minimum fixture that
        // isolates the RESTRICT foreign key from the CASCADE one.
        DB::table('stock_movements')->insert([
            'item_id' => $this->resin->id,
            'warehouse_id' => $restrictStore->id,
            'type' => 'receipt',
            'quantity' => self::RECEIPT_QTY,
            'movement_date' => now()->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(
            $lifecycle->report($restrictStore)->isClear(),
            'RECORDED GAP: a RESTRICT child is invisible to the declaration too',
        );

        try {
            $lifecycle->delete($restrictStore);
            $this->fail('the database must refuse this delete even though the report was clear');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'FOREIGN KEY',
                mb_strtoupper($e->getMessage()),
                'RECORDED GAP: the refusal arrives as a driver error, not as the contract 422 with counts',
            );
        }

        $this->assertNotNull(
            Warehouse::withTrashed()->find($restrictStore->id),
            'it does fail closed: the row survives',
        );
    }

    // =========================================================================
    // THE WIRING LINK — NOT TESTED, and this is the fact that makes it so
    // =========================================================================

    /**
     * Chain D's entity links cannot be walked through routes because no
     * module declares a lifecycle and no lifecycle route exists. That is
     * recorded here as a checked FACT rather than a claim in a report, so
     * the day it changes this test fails and the chain is re-walked at the
     * route layer it then deserves.
     */
    public function test_dw_no_module_declares_a_configuration_lifecycle_yet_so_the_entity_links_are_not_testable(): void
    {
        $declaring = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'ManagesConfigurationLifecycle')) {
                $declaring[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $declaring,
            'a module now declares a configuration lifecycle: chain D must be re-walked through that entity\'s routes',
        );

        // ...and the ONE lifecycle-shaped route the app serves predates the
        // contract and does not go through it: ProductionConfigurationService
        // ::deactivate() writes `status = inactive` itself, with no ability
        // check, no dependency report and no `reason`. It is the module's own
        // workflow step, not the contract's Archive — which is why the
        // Deactivate link above is walked on the mechanism instead.
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'archive')
                || str_contains($uri, 'activate')
                || str_contains($uri, 'deactivate'))
            ->values()
            ->all();

        $this->assertSame(
            ['POST api/v1/production/configurations/{production_configuration}/deactivate'],
            $uris,
            'a NEW lifecycle route now exists: chain D must be re-walked through it',
        );
        $this->assertStringNotContainsString(
            'ManagesConfigurationLifecycle',
            (string) file_get_contents(app_path('Modules/Production/Services/ProductionConfigurationService.php')),
            'that deactivate route now runs through the shared mechanism: walk it as the contract Archive',
        );

        // The record the resource does NOT carry: `can`, the block the shared
        // frontend reads instead of re-deriving eligibility.
        $warehouse = $this->createWarehouseThroughTheRoute('ACC-CFG-CAN', 'ACC Configuration Can');
        $shown = $this->getJson('/api/v1/inventory/warehouses')->json('data');
        $row = collect($shown)->firstWhere('id', $warehouse->id);

        $this->assertArrayNotHasKey(
            'can',
            $row,
            'a resource now serves the `can` block: chain D\'s eligibility link becomes walkable and must be walked',
        );
    }
}
