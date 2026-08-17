<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE STORE ISSUE — the handover the factory always performed and the ERP
 * never recorded (Phase 7.5, WS-B).
 *
 * Until now stock left the store only at batch completion, as a
 * consumption. The lead's correction of 17-Aug makes the real sequence
 * explicit: Store Stock → Issued to Production → Consumed, with unused
 * material coming back. These three tables are the middle step's paperwork;
 * the middle STATE itself is a location (Production/WIP, DEC-20260817-001)
 * and lives in stock_movements as an ordinary signed transfer pair, so
 * `inventory:check-ledger` keeps working untouched.
 *
 * ADDITIVE AND REVERSIBLE. Nothing existing is altered: no column is
 * dropped, no row is rewritten, day_bin_movements is not touched, and
 * down() drops only what up() created.
 *
 * material_request_id / material_request_line_id ARE INDEXED BUT CARRY NO
 * FOREIGN KEY — on purpose, and worth reading before "fixing" it. The
 * request tables are built by the same phase in a parallel workstream, so a
 * constraint here would order two migrations that have no ordering. The
 * link is validated at the API edge (`exists:material_request_lines,id`) and
 * the quantity a line was requested at is SNAPSHOT onto the issue line, so
 * "how much of this request is still outstanding" is answerable entirely
 * from these tables, from the day they exist, whatever happens to the
 * request afterwards. The constraint can be added later; the index is what
 * makes the read fast either way.
 *
 * NO MONEY ANYWHERE IN THESE TABLES. Rates, amounts and vendor identity are
 * Owner/Accounts (FC-06); a store issue is kilograms, bags, people and
 * times. The valuation the transfer carries stays where it always has —
 * on stock_movements.unit_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number', 32)->unique();

            // The request this issue fulfils, when there is one. Nullable
            // because the store may also hand material over against a verbal
            // ask and record it — refusing that would simply move the record
            // back off the system.
            $table->unsignedBigInteger('material_request_id')->nullable()->index();

            $table->string('status', 24)->index();

            // WHO, both sides. The store hand and the production hand are
            // different people and the pair is the whole point of a handover
            // record; issued_by is the authenticated user, received_by is
            // named by them.
            $table->foreignId('issued_by')->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('issued_at')->index();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('cancellation_reason', 500)->nullable();

            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('store_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_issue_id')->constrained('store_issues')->cascadeOnDelete();

            $table->unsignedBigInteger('material_request_line_id')->nullable()->index();
            // What the request asked for, frozen at the moment of issue —
            // the same idiom the completion shortfall uses for item names.
            // "Remaining" is this minus the sum of what has been issued
            // against the line, and is therefore computable here forever.
            $table->decimal('quantity_requested', 18, 4)->nullable();

            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');

            $table->decimal('quantity_issued', 18, 4);
            $table->decimal('quantity_returned', 18, 4)->default(0);
            $table->string('uom', 16)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('store_issue_bag_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_issue_id')->constrained('store_issues')->cascadeOnDelete();
            $table->foreignId('store_issue_line_id')->constrained('store_issue_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('material_request_line_id')->nullable()->index();

            $table->foreignId('material_bag_id')->constrained('material_bags');
            $table->foreignId('material_lot_id')->constrained('material_lots');
            $table->decimal('quantity_kg', 18, 4);

            $table->foreignId('issued_by')->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('scanned_at')->index();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // NO work_center_id AND NO batch/entry COLUMN, and neither is an
            // omission. FC-01 and DEC-20260807-006: resin enters through one
            // common piped loading point, so a bag belongs to no machine;
            // and the trace stops at the ISSUE — the ERP says which bags went
            // to production, never that a particular batch used them. A
            // nullable column here would be an invitation to start writing
            // that claim again.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_issue_bag_scans');
        Schema::dropIfExists('store_issue_lines');
        Schema::dropIfExists('store_issues');
    }
};
