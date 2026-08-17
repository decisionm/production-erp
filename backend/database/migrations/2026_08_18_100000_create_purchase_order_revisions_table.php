<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_order_revisions — the append-only memory of a purchase order's
 * lifecycle (Phase 6, P6-01). A row is written by exactly two actions and
 * never updated or deleted:
 *
 *   kind 'amend'  — a Draft's lines were replaced; lines_json holds the
 *                   PRIOR lines (item, quantity, rate, schedules) so the
 *                   order's history is readable without a diff tool;
 *   kind 'close'  — a Sent / PartiallyReceived order was short-closed;
 *                   lines_json holds what was still open per line at that
 *                   moment (quantity, received, remaining).
 *
 * revision_no counts from 1 per order across both kinds; the pair is
 * unique. amended_by is the acting user (nullable: an internal caller may
 * have none). Additive and reversible: no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_no');
            $table->string('kind', 16); // amend | close
            $table->json('lines_json');
            $table->foreignId('amended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['purchase_order_id', 'revision_no'], 'po_revisions_order_no_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_revisions');
    }
};
