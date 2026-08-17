<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Download / Export Center's audit (MASTER-PLAN Phase 4.5): one row per
 * POST /exports/{kind}, INCLUDING refusals (a blocked kind, a cap hit), so
 * the record says who tried what — not only who succeeded. See
 * App\Modules\Core\Models\ExportRun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            // The ExportKind key ('tally_sync_entries'); a string, not an
            // FK — kinds are code, not rows.
            $table->string('kind', 64);
            // The validated filters the run was asked with, as sent.
            $table->json('filters');
            // On success: rows streamed. On a cap refusal: rows that
            // matched (the count that was refused). 0 for a blocked kind.
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_name');
            // sha256 of the streamed bytes, lowercase hex — stamped when the
            // last byte is out; NULL until then and on every refusal.
            $table->char('sha256', 64)->nullable();
            $table->boolean('completed')->default(false);
            // Why the server refused (the exact sentence the client saw);
            // NULL for a run that streamed.
            $table->string('refusal_reason')->nullable();
            // created_at only — never edited beyond the completion stamp.
            $table->timestamp('created_at')->nullable();

            // "My recent downloads, newest first" is the only read.
            $table->index(['user_id', 'created_at'], 'export_runs_user_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_runs');
    }
};
