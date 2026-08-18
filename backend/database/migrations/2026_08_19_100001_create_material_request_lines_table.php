<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One material asked for, on one request.
 *
 * `uom` is a SNAPSHOT of the item's own unit at the moment the request was
 * raised — never typed by the requester and never invented. An item with no
 * unit recorded leaves this NULL: a missing factory value is reported
 * missing (AGENTS.md), not filled in with a plausible one.
 *
 * `issued_quantity` is the running total the STORE has handed over against
 * this line. It is written ONLY by MaterialRequestService::
 * applyIssuedQuantities (which the store-issue flow calls) so that
 * "remaining" and the request's status are derived in exactly one place.
 * It is NOT a consumption: material that has been issued is standing in
 * Production/WIP (DEC-20260817-001) until a batch consumes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            // Snapshot of the item's unit at request time; NULL when the item
            // carries none.
            $table->string('uom', 32)->nullable();
            $table->decimal('issued_quantity', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_lines');
    }
};
