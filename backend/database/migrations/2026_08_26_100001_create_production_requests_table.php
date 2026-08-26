<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PRODUCTION REQUEST — "the store cannot cover this order line out of
 * the finished-goods store; the floor has to make the rest".
 *
 * PAPERWORK, LIKE THE MATERIAL REQUEST BEFORE IT. It creates no batch, it
 * starts no batch, it cancels no batch and it holds no FK into
 * shift_production_entries (invariant 2 of this build). People start
 * batches; this row only says what the factory is being asked for and in
 * what order. `in_progress` means a person told the queue they had picked
 * the job up — nothing in the ERP infers it from a running batch.
 *
 * IT IS NOT A MATERIAL REQUEST. That document faces the STORE and asks to
 * be handed material the factory already owns; this one faces the FLOOR and
 * asks for finished goods that do not exist yet. Different tables,
 * different enums, different permission story — do not merge them and do
 * not read one as evidence about the other.
 *
 * PRIORITY IS DENSE AND HAS NO UNIQUE INDEX, deliberately. reorder()
 * rewrites the whole queue's priorities in ONE transaction holding the rows
 * locked, and a unique index would make the intermediate states of that
 * rewrite (two rows momentarily sharing a number) fail on the way to a
 * perfectly good final state.
 *
 * ONE OPEN REQUEST PER LINE (queued|in_progress) is a real rule and it is
 * enforced in ProductionRequestService under a lock, NOT here: MySQL has no
 * partial unique index, so "unique where status in (...)" cannot be
 * expressed as a constraint. A cancelled or produced request must be able
 * to sit beside a new one on the same line.
 *
 * NO COST COLUMN and no rate (FC-06); no ETA column anywhere (S11 — an ETA
 * is computed on read by FulfilmentPlanningService and never persisted,
 * because a stored one is wrong the moment the queue is reordered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();
            // Denormalised from the line so the queue can be read, filtered
            // and grouped by item without joining Sales on every row. The
            // line remains the authority; the service copies it at creation
            // and nothing ever edits it.
            // RESTRICT, not CASCADE, for the same reason the hold's item
            // is (DEC-20260817-002): a request is a transactional document,
            // and a hard-deleted item master must be REFUSED by the
            // lifecycle contract with a count, never allowed to sweep live
            // requests off the floor's worklist. Declared in
            // ItemService::dependencyChecks().
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            $table->decimal('quantity', 15, 4);

            // Dense, 1-based, rewritten wholesale by reorder(). See above for
            // why there is no unique index.
            $table->integer('priority')->default(0);

            // queued | in_progress | produced | cancelled
            $table->string('status', 16)->default('queued');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // Either side may withdraw one, and a withdrawal always carries a
            // reason (the OR-gate route, P3).
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            // THE QUEUE ITSELF — open requests in priority order.
            $table->index(['status', 'priority']);
            // The one-open-request check, and the line's own request on the
            // fulfilment queue row.
            $table->index('sales_order_line_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_requests');
    }
};
