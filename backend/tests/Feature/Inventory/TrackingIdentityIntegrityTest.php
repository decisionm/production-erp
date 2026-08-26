<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Http\Requests\StoreStockIssueRequest;
use App\Modules\Inventory\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `items.tracking_type` HAS BEEN A LABEL, NOT A RULE.
 *
 * The column has existed since 19-Jul-2026 and the SPA honours it — the Stock
 * modals show a Batch picker for a batch-tracked item and a Serial picker for
 * a serial-tracked one. NOTHING ON THE SERVER CHECKED ANY OF IT. Every refusal
 * below is reproducible with one curl against `/api/v1/inventory/*`:
 *
 *   A. A batch could be created against an item that is not batch-tracked —
 *      or against an ARCHIVED item — and a serial number likewise. The only
 *      rules were `exists:items,id` and per-item uniqueness.
 *   B. A generic receipt/issue/transfer took ANY `batch_id` or
 *      `serial_number_id` that existed, including one belonging to a
 *      DIFFERENT ITEM. Item A's movement then carried item B's batch, and
 *      BatchService::ledger — which derives a batch's whereabouts purely from
 *      the movements tagged with it — reported material that was never in it.
 *      Cross-item corruption of the traceability record, silent, permanent
 *      (the ledger is append-only).
 *   C. A batch/serial-tracked item could move with NO identity at all, which
 *      is the same batch column left null on a lot the factory is supposed to
 *      be able to trace.
 *   D. A serial number could be ISSUED TWICE. `recordIssue` flips the row to
 *      `consumed`, but nothing read the status first, so the same physical
 *      unit could be issued from any warehouse, any number of times — and
 *      transferred out of a store it had already left.
 *
 * WHERE EACH RULE LIVES, and why not both in one place:
 *
 *   - Tracking mode, serial STATE and serial LOCATION are HTTP-layer rules
 *     (the three FormRequests). They govern what a client may ASK FOR through
 *     the generic doors. Putting them in StockMovementService would re-judge
 *     production, Tally sync and goods receipt — writers that pass no
 *     identity at all and whose behaviour is out of scope here.
 *   - "The batch/serial belongs to the item" is a SERVICE-layer invariant,
 *     because it is never right for any caller. WorkOrderService is the only
 *     other writer that passes a batch id, and it creates that batch for the
 *     work order's own item, so the invariant is silent there.
 *
 * WHAT IS DELIBERATELY NOT CHANGED: reads. A batch, a serial number or a
 * movement that already names a retired item still lists and still renders —
 * the last two tests pin that, the same way ActiveSelectionTest pins it for
 * the masters. Widening what may be WRITTEN must never narrow what may be
 * READ.
 */
class TrackingIdentityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Item $untracked;

    private Item $batchTracked;

    private Item $serialTracked;

    private Warehouse $store;

    private Warehouse $farStore;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->untracked = Item::create([
            'sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::None,
        ]);
        $this->batchTracked = Item::create([
            'sku' => 'RM-MB', 'name' => 'Masterbatch Amber', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::Batch,
        ]);
        $this->serialTracked = Item::create([
            'sku' => 'AS-MOULD', 'name' => 'Mould Insert', 'uom' => 'Nos',
            'tracking_type' => ItemTrackingType::Serial,
        ]);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->farStore = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);
    }

    // ---- A · creating an identity ------------------------------------------

    public function test_a_batch_may_only_be_created_for_a_batch_tracked_item(): void
    {
        // The half that catches an over-wide refusal: the RIGHT item still works.
        $this->postJson('/api/v1/inventory/batches', [
            'item_id' => $this->batchTracked->id,
            'batch_number' => 'MB-2026-01',
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/batches', [
            'item_id' => $this->untracked->id,
            'batch_number' => 'PET-2026-01',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');

        $this->postJson('/api/v1/inventory/batches', [
            'item_id' => $this->serialTracked->id,
            'batch_number' => 'MOULD-2026-01',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');
    }

    public function test_a_serial_number_may_only_be_registered_for_a_serial_tracked_item(): void
    {
        $this->postJson('/api/v1/inventory/serial-numbers', [
            'item_id' => $this->serialTracked->id,
            'serial_number' => 'SN-0001',
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/serial-numbers', [
            'item_id' => $this->untracked->id,
            'serial_number' => 'SN-0002',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');

        $this->postJson('/api/v1/inventory/serial-numbers', [
            'item_id' => $this->batchTracked->id,
            'serial_number' => 'SN-0003',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');
    }

    public function test_an_archived_item_takes_no_new_batch_and_no_new_serial_number(): void
    {
        $this->batchTracked->update(['is_active' => false]);
        $this->serialTracked->update(['is_active' => false]);

        $this->postJson('/api/v1/inventory/batches', [
            'item_id' => $this->batchTracked->id,
            'batch_number' => 'MB-2026-02',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');

        $this->postJson('/api/v1/inventory/serial-numbers', [
            'item_id' => $this->serialTracked->id,
            'serial_number' => 'SN-0004',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');
    }

    // ---- B · an identity that belongs to another item ------------------------

    public function test_no_generic_door_accepts_a_batch_that_belongs_to_another_item(): void
    {
        $foreignBatch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-FOREIGN']);
        $ownBatch = Batch::create(['item_id' => $this->secondBatchItem()->id, 'batch_number' => 'MB-OWN']);
        $target = $this->secondBatchItem();

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $target->id, 'warehouse_id' => $this->store->id,
            'quantity' => '10', 'unit_cost' => '100', 'batch_id' => $ownBatch->id,
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $target->id, 'warehouse_id' => $this->store->id,
            'quantity' => '10', 'unit_cost' => '100', 'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $target->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $target->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');
    }

    public function test_no_generic_door_accepts_a_serial_number_that_belongs_to_another_item(): void
    {
        $second = Item::create([
            'sku' => 'AS-TOOL', 'name' => 'Tool Head', 'uom' => 'Nos',
            'tracking_type' => ItemTrackingType::Serial,
        ]);
        $foreign = SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-FOREIGN',
            'status' => SerialNumberStatus::Registered,
        ]);

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $second->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'unit_cost' => '500', 'serial_number_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->assertSame(
            SerialNumberStatus::Registered,
            $foreign->fresh()->status,
            'the refused receipt still moved the other item\'s serial number into stock',
        );
    }

    public function test_the_service_itself_refuses_a_cross_item_batch_or_serial_tag(): void
    {
        // The invariant below the HTTP layer: no caller, ever, may tag one
        // item's movement with another item's identity.
        $stock = app(StockMovementService::class);
        $foreignBatch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-SVC']);
        $foreignSerial = SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-SVC',
            'status' => SerialNumberStatus::Registered,
        ]);

        foreach ([
            'batch' => fn () => $stock->recordReceipt(
                $this->untracked->id, $this->store->id, '5', '100', batchId: $foreignBatch->id,
            ),
            'serial' => fn () => $stock->recordReceipt(
                $this->untracked->id, $this->store->id, '5', '100', serialNumberId: $foreignSerial->id,
            ),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("the service accepted a {$label} belonging to another item");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(0, StockMovement::query()->where('item_id', $this->untracked->id)->count());
    }

    // ---- C · required / forbidden by tracking mode ---------------------------

    public function test_an_untracked_item_is_refused_any_batch_or_serial_identity(): void
    {
        $batch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-X']);
        $serial = SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-X',
            'status' => SerialNumberStatus::Registered,
        ]);

        // Still moves cleanly with no identity — the ordinary case, unchanged.
        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->untracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '100', 'unit_cost' => '132',
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->untracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '10', 'unit_cost' => '132', 'batch_id' => $batch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->untracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '10', 'unit_cost' => '132', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');
    }

    public function test_a_batch_tracked_item_must_name_its_batch_on_every_generic_door(): void
    {
        $batch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-REQ']);

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->batchTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '50', 'unit_cost' => '210',
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->batchTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '50', 'unit_cost' => '210', 'batch_id' => $batch->id,
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->batchTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '5',
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->batchTracked->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '5',
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        // A batch-tracked item is not a serial one: the wrong identity is
        // refused even though it names the right item.
        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->batchTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '5', 'batch_id' => $batch->id,
            'serial_number_id' => SerialNumber::create([
                'item_id' => $this->batchTracked->id, 'serial_number' => 'SN-WRONG',
                'status' => SerialNumberStatus::Registered,
            ])->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');
    }

    public function test_a_serial_tracked_item_must_name_its_serial_number_on_every_generic_door(): void
    {
        $serial = $this->registeredSerial('SN-REQ');

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'unit_cost' => '500',
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'unit_cost' => '500', 'serial_number_id' => $serial->id,
        ])->assertSuccessful();

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->serialTracked->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');
    }

    // ---- D · a serial number's state and its whereabouts ---------------------

    public function test_a_serial_number_cannot_be_issued_twice(): void
    {
        $serial = $this->receivedSerial('SN-DUP', $this->store);

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertSuccessful();

        $this->assertSame(SerialNumberStatus::Consumed, $serial->fresh()->status);

        // THE SECOND ISSUE — the same physical unit, consumed twice.
        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->store->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        // Its receipt is tagged with it too, so count the ISSUES only.
        $this->assertSame(
            1,
            $serial->movements()->where('type', StockMovementType::Issue)->count(),
            'the serial number picked up a second issue movement',
        );
    }

    public function test_a_serial_number_cannot_be_issued_from_a_store_it_is_not_in(): void
    {
        $serial = $this->receivedSerial('SN-ELSEWHERE', $this->store);

        // Give the far store its own balance, so the refusal is about the
        // serial's whereabouts and not about there being no stock there.
        app(StockMovementService::class)->recordReceipt(
            $this->serialTracked->id, $this->farStore->id, '5', '500',
        );

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->assertSame(SerialNumberStatus::InStock, $serial->fresh()->status);
        $this->assertSame($this->store->id, $serial->fresh()->warehouse_id);
    }

    public function test_a_serial_number_that_is_not_in_stock_cannot_be_transferred(): void
    {
        $serial = $this->registeredSerial('SN-NEVER-RECEIVED');

        app(StockMovementService::class)->recordReceipt(
            $this->serialTracked->id, $this->store->id, '5', '500',
        );

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->serialTracked->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->assertNull($serial->fresh()->warehouse_id);
    }

    public function test_a_serial_number_cannot_be_transferred_out_of_a_store_it_has_already_left(): void
    {
        $serial = $this->receivedSerial('SN-MOVED', $this->store);

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->serialTracked->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertSuccessful();

        $this->assertSame($this->farStore->id, $serial->fresh()->warehouse_id);

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->serialTracked->id,
            'from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');
    }

    /**
     * A DELIBERATE WIDENING beyond issue/transfer, recorded as one.
     *
     * Receiving a serial number that is already in stock does not mint a
     * second unit — it silently re-stamps the one row's warehouse, so the
     * unit teleports and its old store keeps the quantity. The SPA already
     * only offers `registered` serials on a receipt, and no service caller
     * passes a serial id at all, so nothing legitimate is narrowed by making
     * the server say the same thing.
     */
    public function test_a_serial_number_already_in_stock_cannot_be_received_again(): void
    {
        $serial = $this->receivedSerial('SN-TELEPORT', $this->store);

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->serialTracked->id, 'warehouse_id' => $this->farStore->id,
            'quantity' => '1', 'unit_cost' => '500', 'serial_number_id' => $serial->id,
        ])->assertStatus(422)->assertJsonValidationErrors('serial_number_id');

        $this->assertSame($this->store->id, $serial->fresh()->warehouse_id);
    }

    // ---- reads are untouched -------------------------------------------------

    public function test_a_batch_and_a_serial_number_of_an_archived_item_still_list(): void
    {
        Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-HISTORY']);
        SerialNumber::create([
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-HISTORY',
            'status' => SerialNumberStatus::Registered,
        ]);

        $this->batchTracked->update(['is_active' => false]);
        $this->serialTracked->update(['is_active' => false]);

        $this->getJson('/api/v1/inventory/batches')
            ->assertSuccessful()
            ->assertJsonFragment(['batch_number' => 'MB-HISTORY']);

        $this->getJson('/api/v1/inventory/serial-numbers')
            ->assertSuccessful()
            ->assertJsonFragment(['serial_number' => 'SN-HISTORY']);
    }

    public function test_a_movement_already_tagged_with_a_batch_still_reads_its_ledger_back(): void
    {
        $batch = Batch::create(['item_id' => $this->batchTracked->id, 'batch_number' => 'MB-LEDGER']);
        app(StockMovementService::class)->recordReceipt(
            $this->batchTracked->id, $this->store->id, '40', '210', batchId: $batch->id,
        );

        $this->batchTracked->update(['is_active' => false]);

        $this->getJson("/api/v1/inventory/batches/{$batch->id}/ledger")
            ->assertSuccessful()
            ->assertJsonPath('data.batch.batch_number', 'MB-LEDGER')
            ->assertJsonPath('data.on_hand.0.quantity', '40.0000');
    }

    // ---- E · the writer itself, not just the door ----------------------------

    /**
     * THE DOOR PROVED IT A MOMENT AGO; THE WRITE MUST PROVE IT NOW.
     *
     * The FormRequest refuses a serial number that is not in stock, but it
     * judges that BEFORE the transaction opens, so what it establishes is that
     * the unit was in stock a moment ago. The status update inside the
     * transaction was unconditional and nothing re-read the row, so a second
     * issue of the same unit still wrote.
     *
     * THE BALANCE OF 5 IS THE POINT OF THIS TEST. With one unit in the store
     * the second issue dies on InsufficientStockException and the hole looks
     * closed. A store holding other units of the same item has quantity to
     * spare, the quantity check passes, and one physical unit is consumed
     * twice — two Issue movements, the balance down by two.
     */
    public function test_the_writer_refuses_a_second_issue_of_one_serial_even_with_stock_to_spare(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->registeredSerial('SN-WRITER');

        $stock->recordReceipt(
            $this->serialTracked->id, $this->store->id, '5', '500', serialNumberId: $serial->id,
        );
        $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);
        $this->assertSame(SerialNumberStatus::Consumed, $serial->fresh()->status);

        try {
            $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);
            $this->fail('the writer issued one physical unit twice');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number_id', $exception->errors());
        }

        $this->assertSame(
            1,
            $serial->movements()->where('type', StockMovementType::Issue)->count(),
            'the serial number picked up a second issue movement',
        );
    }

    public function test_the_writer_refuses_a_transfer_of_a_unit_that_has_already_been_consumed(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->registeredSerial('SN-WRITER-TX');

        $stock->recordReceipt(
            $this->serialTracked->id, $this->store->id, '5', '500', serialNumberId: $serial->id,
        );
        $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);

        try {
            $stock->recordTransfer(
                $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
                serialNumberId: $serial->id,
            );
            $this->fail('the writer relocated a unit that is no longer in stock');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number_id', $exception->errors());
        }

        $this->assertNull($serial->fresh()->warehouse_id, 'a consumed unit was given a location again');
    }

    /**
     * ONE PHYSICAL UNIT LEAVES A STORE ONCE — the transfer half, which the
     * status condition alone did NOT cover.
     *
     * An issue flips the row to `consumed`, so a second issue finds a status
     * that no longer matches and is refused. A transfer changes nothing about
     * the status: the unit is `in_stock` before and `in_stock` after. So a
     * status-only condition was satisfied by the second transfer exactly as
     * it was by the first, and RM-STORE was decremented twice for one unit
     * that left it once.
     *
     * BALANCE 5, NOT 1, ON PURPOSE — twice. The store holds four other units
     * of the same material, so the quantity check has stock to spare and the
     * double decrement goes through silently. At balance 1 the hole looks
     * closed by arithmetic that has nothing to do with the unit.
     */
    public function test_the_writer_refuses_a_second_transfer_of_a_unit_out_of_the_store_it_has_left(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->receivedSerial('SN-TX-TWICE', $this->store);
        $stock->recordReceipt($this->serialTracked->id, $this->store->id, '4', '500');

        $stock->recordTransfer(
            $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
            serialNumberId: $serial->id,
        );

        try {
            $stock->recordTransfer(
                $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
                serialNumberId: $serial->id,
            );
            $this->fail('the writer moved one physical unit out of RM-STORE twice');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number_id', $exception->errors());
        }

        $this->assertSame('4.0000', $this->quantityAt($this->store), 'RM-STORE was decremented twice for one unit');
        $this->assertSame('1.0000', $this->quantityAt($this->farStore), 'FG-STORE was incremented twice for one unit');
        $this->assertSame(
            1,
            $serial->movements()->where('type', StockMovementType::TransferOut)->count(),
            'the unit picked up a second transfer-out movement',
        );
    }

    /**
     * THE INTERLEAVING ITSELF, in the order two concurrent requests actually
     * hit the database: door(A), door(B), write(A), write(B).
     *
     * Both requests are approved BEFORE either writes, and both approvals are
     * honest — at the moment each was judged, the unit really was in stock in
     * RM-STORE. That is the whole reason the door cannot be the guard: what it
     * proves is where the unit was A MOMENT AGO. Only the write, reading the
     * row it is about to change, can prove where the unit is now.
     *
     * `lockForUpdate` compiles to nothing on SQLite and this suite runs on one
     * in-memory connection, so what this test actually exercises is the
     * conditional update and its affected-row count. Under MySQL the row lock
     * is what serialises the two writes into this order in the first place;
     * both are kept, because either alone would be a guard that holds on one
     * driver.
     */
    public function test_two_transfers_that_both_passed_the_door_move_one_unit_once(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->receivedSerial('SN-RACE', $this->store);
        $stock->recordReceipt($this->serialTracked->id, $this->store->id, '4', '500');

        $payload = [
            'item_id' => $this->serialTracked->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $this->farStore->id,
            'quantity' => '1',
            'serial_number_id' => $serial->id,
        ];

        $this->assertTrue($this->doorApproves($payload), 'the first request was refused at the door');
        $this->assertTrue($this->doorApproves($payload), 'the second request was refused at the door before any write');

        $stock->recordTransfer(
            $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
            serialNumberId: $serial->id,
        );

        try {
            $stock->recordTransfer(
                $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
                serialNumberId: $serial->id,
            );
            $this->fail('two approved requests moved one physical unit twice');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number_id', $exception->errors());
        }

        $this->assertSame('4.0000', $this->quantityAt($this->store));
        $this->assertSame('1.0000', $this->quantityAt($this->farStore));
        $this->assertSame($this->farStore->id, $serial->fresh()->warehouse_id);
    }

    /**
     * THE SAME RACE ON AN ISSUE, and the balance is the assertion.
     *
     * The status condition already refused the second issue; what was never
     * pinned is that RM-STORE keeps the four units it still holds. A refusal
     * that rolls back half a transaction is not a refusal.
     */
    public function test_two_issues_that_both_passed_the_door_take_one_unit_once(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->receivedSerial('SN-RACE-ISSUE', $this->store);
        $stock->recordReceipt($this->serialTracked->id, $this->store->id, '4', '500');

        $payload = [
            'item_id' => $this->serialTracked->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '1',
            'serial_number_id' => $serial->id,
        ];

        $this->assertTrue($this->issueDoorApproves($payload), 'the first request was refused at the door');
        $this->assertTrue($this->issueDoorApproves($payload), 'the second request was refused at the door before any write');

        $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);

        try {
            $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);
            $this->fail('two approved requests issued one physical unit twice');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number_id', $exception->errors());
        }

        $this->assertSame('4.0000', $this->quantityAt($this->store), 'the refused issue still took a unit off the balance');
    }

    /**
     * NAMING THE SOURCE STORE MUST NOT NARROW WHAT ALREADY PASSED. judgeLeaving
     * lets a unit with NO recorded location leave any store rather than
     * refusing it, and the writer keeps exactly that tolerance — the guard is
     * against a unit that has demonstrably moved elsewhere, not against a
     * row nobody ever stamped.
     */
    public function test_a_unit_with_no_recorded_location_still_transfers(): void
    {
        $stock = app(StockMovementService::class);
        $stock->recordReceipt($this->serialTracked->id, $this->store->id, '5', '500');

        $serial = SerialNumber::create([
            'item_id' => $this->serialTracked->id,
            'serial_number' => 'SN-NOWHERE',
            'status' => SerialNumberStatus::InStock,
            'warehouse_id' => null,
        ]);

        $stock->recordTransfer(
            $this->serialTracked->id, $this->store->id, $this->farStore->id, '1',
            serialNumberId: $serial->id,
        );

        $this->assertSame($this->farStore->id, $serial->fresh()->warehouse_id);
    }

    /**
     * A RECEIPT IS DELIBERATELY NOT GUARDED THE SAME WAY. Whether a consumed
     * unit may come back in is an open question about returns that the factory
     * has not answered, and refusing it in the writer would be answering it.
     * This pins the silence, so a later reader does not mistake it for an
     * oversight and close it without asking.
     */
    public function test_the_writer_leaves_the_returns_question_open(): void
    {
        $stock = app(StockMovementService::class);
        $serial = $this->registeredSerial('SN-RETURN');

        $stock->recordReceipt(
            $this->serialTracked->id, $this->store->id, '5', '500', serialNumberId: $serial->id,
        );
        $stock->recordIssue($this->serialTracked->id, $this->store->id, '1', serialNumberId: $serial->id);

        // Not asserted to be RIGHT — asserted to be UNDECIDED here rather than
        // silently settled by a guard nobody asked for.
        $stock->recordReceipt(
            $this->serialTracked->id, $this->store->id, '1', '500', serialNumberId: $serial->id,
        );

        $this->assertSame(SerialNumberStatus::InStock, $serial->fresh()->status);
    }

    /**
     * `Rule::exists` QUERIES THE TABLE, NOT THE MODEL, so it does not apply
     * the SoftDeletes scope — and deleting an item does not clear `is_active`.
     * A soft-deleted item therefore sat there passing an `is_active = true`
     * check and taking brand-new lots. StoreStoreIssueRequest spells the same
     * `deleted_at` clause out for exactly this reason.
     */
    public function test_a_soft_deleted_item_takes_no_new_batch_and_no_new_serial_number(): void
    {
        $this->batchTracked->delete();
        $this->serialTracked->delete();

        $this->postJson('/api/v1/inventory/batches', [
            'item_id' => $this->batchTracked->id, 'batch_number' => 'AFTER-DELETE',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');

        $this->postJson('/api/v1/inventory/serial-numbers', [
            'item_id' => $this->serialTracked->id, 'serial_number' => 'SN-AFTER-DELETE',
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');
    }

    // ---- helpers -------------------------------------------------------------

    private function secondBatchItem(): Item
    {
        return Item::firstOrCreate(
            ['sku' => 'RM-MB2'],
            ['name' => 'Masterbatch White', 'uom' => 'Kgs', 'tracking_type' => ItemTrackingType::Batch],
        );
    }

    private function registeredSerial(string $number): SerialNumber
    {
        return SerialNumber::create([
            'item_id' => $this->serialTracked->id,
            'serial_number' => $number,
            'status' => SerialNumberStatus::Registered,
        ]);
    }

    private function quantityAt(Warehouse $warehouse): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $this->serialTracked->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');
    }

    /**
     * Runs the REAL FormRequest — rules and `withValidator` both — without a
     * write behind it, so a test can ask "would the door have let this
     * through at this instant" and then choose when the write lands.
     */
    private function doorApproves(array $payload): bool
    {
        return $this->doorApprovesVia(StoreStockTransferRequest::class, $payload);
    }

    private function issueDoorApproves(array $payload): bool
    {
        return $this->doorApprovesVia(StoreStockIssueRequest::class, $payload);
    }

    /** @param  class-string<FormRequest>  $requestClass */
    private function doorApprovesVia(string $requestClass, array $payload): bool
    {
        $request = $requestClass::create('/', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setContainer(app())->setRedirector(app(Redirector::class));

        try {
            $request->validateResolved();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function receivedSerial(string $number, Warehouse $warehouse): SerialNumber
    {
        $serial = $this->registeredSerial($number);

        app(StockMovementService::class)->recordReceipt(
            $this->serialTracked->id, $warehouse->id, '1', '500', serialNumberId: $serial->id,
        );

        return $serial->fresh();
    }
}
