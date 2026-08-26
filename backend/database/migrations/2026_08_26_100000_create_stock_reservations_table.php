<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE STOCK RESERVATION — "this much of what is already in the FG store is
 * spoken for by that customer's order line".
 *
 * A HOLD, NOT A MOVEMENT. Nothing in this table moves stock, and nothing in
 * it may ever be read as having moved stock: the balance in
 * stock_balances is unchanged by a reservation and only a DELIVERY (Sales'
 * DeliveryService → StockMovementService) ever decrements it. That is
 * invariant 1 of this build and the reason there is no movement id here.
 *
 * Additive and reversible: one new table, nothing altered, nothing
 * back-filled. No `deleted_at` and no activity log — this is a
 * TRANSACTIONAL document, not a configuration master (CLAUDE.md: masters
 * soft-delete, transactions stay append-only). A hold that is given up
 * keeps its row with its reason and its author.
 *
 * THE THREE QUANTITIES are independent facts and none is derived from
 * another at read time:
 *   quantity           what was held
 *   consumed_quantity  how much of it actually left on a delivery
 *   released_quantity  how much of it was given up without leaving
 * `status` is MAINTAINED from the three by StockReservationService — active
 * while consumed+released < quantity, else consumed when anything was
 * consumed, else released. It is a column rather than a computed read so
 * the queue can index on it.
 *
 * NO COST COLUMN, deliberately (FC-06). A hold is about pieces; the money
 * lives on the stock balance and stays there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();

            // RESTRICT, NOT CASCADE, on both masters below — and the
            // Configuration Lifecycle Contract (DEC-20260817-002) is why.
            // A hold is a TRANSACTIONAL document: a master proved unused
            // may be hard-deleted, and a cascading child would let that
            // delete silently destroy live holds. Restricting instead
            // means ItemService and WarehouseService declare this table in
            // their dependency checks, and the refusal reaches the screen
            // as the contract's 422-with-counts rather than a 500.
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // WHICH WAREHOUSE THE HOLD IS AGAINST. Carried explicitly rather
            // than resolved at read time: the finished-goods warehouse is a
            // SETTING (FactoryWarehouseResolver) and re-pointing that setting
            // must never silently move every existing hold to a location the
            // stock is not in. Delivery consumes only holds whose warehouse
            // matches the one it dispatched from (S3).
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();

            // decimal(15,4) like every other stock quantity in this ERP —
            // never float (CLAUDE.md).
            $table->decimal('quantity', 15, 4);
            $table->decimal('consumed_quantity', 15, 4)->default(0);
            $table->decimal('released_quantity', 15, 4)->default(0);

            // active | released | consumed — see the class docblock.
            $table->string('status', 16)->default('active');
            // Why the hold was given up: 'line_fulfilled', 'so_cancelled',
            // 'repointed', or whatever the storekeeper typed.
            $table->string('released_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // THE AVAILABILITY READ: sum(active holds) for an item in a
            // warehouse — the query every reserve() runs inside its
            // transaction, and the one the whole queue is built on.
            $table->index(['item_id', 'warehouse_id', 'status']);
            // The line's own holds — the queue row, the release, the repoint.
            $table->index('sales_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
