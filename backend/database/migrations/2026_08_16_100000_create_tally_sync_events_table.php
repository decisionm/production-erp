<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable history of the Tally sync chain (TALLY-SYNC-CHAIN.md §2–3).
 *
 * This persists the event vocabulary TallySyncAgentController::agentLog()
 * ALREADY emits — pending.delivered, voucher.synced, voucher.failed,
 * voucher.failure_refused, masters.received, company.bound,
 * companies.received, stock-summary.previewed — which today lands in a
 * 30-day file on the server and nowhere else, plus the entry-side mutations
 * (enqueue, merge, retry, dismiss, release) that had no trace at all beyond
 * the columns they overwrite. It is HISTORY, not a second sync model: the
 * queue stays tally_sync_entries, the agent reads nothing here, and no
 * status is derived from these rows.
 *
 * tally_sync_entry_id is NULLABLE on purpose: the Tally→ERP flows (a
 * masters pull, a company binding, a stock-summary preview) never create an
 * entry, so this is the first database record of an inbound pull. The
 * direction column is what makes that filterable without inventing a
 * mirror table.
 *
 * APPEND-ONLY. There is a created_at and deliberately no updated_at, the
 * same precedent as material_cost_versions: a row here is an observation of
 * something that happened, and an observation is never edited — a
 * correction is a later event, not a rewrite. The model refuses update and
 * delete in code (TallySyncEvent::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tally_sync_entry_id')->nullable()
                ->constrained('tally_sync_entries')->cascadeOnDelete();
            // The event name, in the file log's own vocabulary
            // (TallySyncEventKind).
            $table->string('event', 64);
            // erp_to_tally | tally_to_erp | none.
            $table->string('direction', 16)->default('erp_to_tally');
            $table->timestamp('occurred_at')->index();
            // user | agent | system. actor_label is the user's name or the
            // agent TOKEN'S NAME (one token per installation) — never the
            // token itself.
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 120)->nullable();
            $table->json('details')->nullable();
            // created_at only. No updated_at: rows are never edited, so a
            // "last modified" would be a lie waiting to be told — mirroring
            // material_cost_versions' created_at-only precedent.
            $table->timestamp('created_at')->nullable();

            // "This entry's history in order" is the hot read; "when did the
            // agent last deliver / when did masters last arrive" is the
            // other. Explicitly named: MySQL rejects identifiers over 64.
            $table->index(['tally_sync_entry_id', 'id'], 'tally_sync_events_entry_seq_index');
            $table->index(['event', 'occurred_at'], 'tally_sync_events_event_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_sync_events');
    }
};
