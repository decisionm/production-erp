<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * THE QUALITY GATE (owner, 30-Jul): "we need one new approval as quality
     * check. All the machines will go to quality queue, and quality will do
     * the check, add entry as how many reviewed, how many okay and how many
     * rejected. This quality-rejected needs to add in the production as
     * rejected by quality, so the total production will reduce if rejection,
     * otherwise same, then go to next level."
     *
     * Deliberately NOT a new `status` value. The batch still lands on
     * 'pending' at completion and the quality check is a PRECONDITION of the
     * PM gate, so every existing queue filter, report and screen that reads
     * the status chain keeps working untouched — the only thing that changes
     * is what pmApprove() will accept.
     *
     * quality_rejected_nos is stored as a COUNT because bottles are counted,
     * not weighed, at this gate; the kg the books need is derived from the
     * run's frozen unit weight and written to the EXISTING qc_rejection_kg,
     * so production.rejection_precedence ('qc') consumes it through the one
     * precedence path that already exists rather than a second one.
     */
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // WHO COMPLETED THE BATCH. Needed because the four-eyes rule now
            // extends to this gate — the person who counted the output must
            // not also be the person who passes it. created_by is the
            // supervisor who STARTED the run and is not the same answer.
            $table->foreignId('completed_by')->nullable()->after('operator_id')->constrained('users')->nullOnDelete();

            // The supervisor's own count, kept whole. quantity_produced is
            // rewritten to the NET figure at the quality check (that is what
            // "the total production will reduce" means, and it is what the
            // voucher's produced line and every report must carry), so the
            // gross has to survive somewhere or the floor's original entry is
            // silently lost.
            $table->decimal('gross_quantity_produced', 15, 4)->nullable()->after('quantity_produced_kg');

            // The check itself: reviewed = ok + rejected, reconciled at the
            // request boundary.
            $table->unsignedBigInteger('quality_reviewed_nos')->nullable()->after('qc_rejection_kg');
            $table->unsignedBigInteger('quality_ok_nos')->nullable()->after('quality_reviewed_nos');
            $table->unsignedBigInteger('quality_rejected_nos')->nullable()->after('quality_ok_nos');
            $table->foreignId('quality_checked_by')->nullable()->after('quality_rejected_nos')->constrained('users')->nullOnDelete();
            $table->timestamp('quality_checked_at')->nullable()->after('quality_checked_by');
            $table->text('quality_note')->nullable()->after('quality_checked_at');

            // WHY NO SCRAP RECEIPT HAPPENED, when none did. The rejected
            // bottles are always issued out of finished goods, but the scrap
            // kg can only be received against a real scrap item, and this
            // ERP has no scrap-item master yet (see config/production.php
            // 'scrap'). Guessing one would corrupt the books, so the
            // rejection is recorded, the receipt is skipped, and the reason
            // is written down HERE where the approval screen can show it —
            // rather than disappearing into a log nobody reads.
            $table->string('quality_scrap_note', 255)->nullable()->after('quality_note');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('quality_checked_by');
            $table->dropColumn([
                'gross_quantity_produced',
                'quality_reviewed_nos',
                'quality_ok_nos',
                'quality_rejected_nos',
                'quality_checked_at',
                'quality_note',
                'quality_scrap_note',
            ]);
        });
    }
};
