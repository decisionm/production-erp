<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Tally stock summary, kept so a person can read it before anything is done
 * with it.
 *
 * The preview endpoint reported and discarded. That was right for safety and
 * wrong for use: the agent got the answer, the server kept nothing, and the
 * only way to find out what Tally said was to read a log file on the factory
 * PC. A snapshot nobody can look at cannot become an opening balance anybody
 * trusts.
 *
 * STORING IS NOT APPLYING, and the two are deliberately separate rows of this
 * table's life. A stored snapshot moves no stock. Applying it is an explicit
 * act, recorded here with who did it and when, and refused a second time —
 * an opening balance posted twice is not a mistake anyone spots by looking at
 * a screen, because the number simply looks bigger than it should.
 *
 * `lines` is JSON rather than a child table on purpose. A snapshot is read
 * whole, applied whole, and never queried line-by-line; a second table would
 * buy nothing and cost a join everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_stock_snapshots', function (Blueprint $table) {
            $table->id();

            // Which company's books this came from, and as at what closing
            // date. Both are the snapshot's identity — the same company read
            // on two dates is two different truths.
            $table->string('company');
            $table->date('as_of');

            $table->json('lines');
            $table->json('totals');

            // pending → applied. Never back.
            $table->string('status', 16)->default('pending');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('applied_line_count')->nullable();

            $table->timestamps();

            $table->index(['company', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_stock_snapshots');
    }
};
