<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SCAN ACKNOWLEDGEMENT — why a machine was topped up while the books
 * still say it holds material.
 *
 * The estimate (FactoryDayBinService::machineResinEstimate) says a machine
 * should still have, say, 18 kg of resin in it. Somebody scans another bag
 * into it anyway. That is not necessarily wrong — the estimate is DERIVED
 * from output, not weighed, so the real bin may genuinely be empty. But it
 * is always worth one word from the person standing at the machine, because
 * the alternative is that the discrepancy is never noticed by anyone.
 *
 * SO THE SCAN ASKS FOR A REASON, NOT FOR A WEIGHT. The factory does not do
 * routine day-bin weighing and this feature does not introduce any: nothing
 * anywhere asks the operator to put material on a scale. It asks them to
 * pick one of four words for what happened to the material the estimate
 * still expects — confirm_extra (it really is still there, we are loading
 * on top), spill, return_to_store, correction (the estimate is wrong) — and
 * optionally to type a short note. Below the threshold nothing is asked at
 * all, so the ordinary scan stays one tap.
 *
 * Recorded on the LOAD ROW rather than in a side table because it is a fact
 * about that scan and nothing else, and because a Load row is already the
 * thing every estimate and allocation reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            // One of confirm_extra | spill | return_to_store | correction.
            // Nullable because the overwhelming majority of scans are below
            // the threshold and are never asked.
            $table->string('balance_ack_reason', 20)->nullable()->after('quantity_kg');

            // The operator's own words, optional even when a reason is
            // required — a forced free-text box collects noise, not signal.
            $table->string('balance_ack_note', 200)->nullable()->after('balance_ack_reason');
        });
    }

    public function down(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            $table->dropColumn(['balance_ack_reason', 'balance_ack_note']);
        });
    }
};
