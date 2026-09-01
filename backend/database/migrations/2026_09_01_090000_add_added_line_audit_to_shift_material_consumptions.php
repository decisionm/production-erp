<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO ADDED A CONSUMPTION LINE THE RUN WAS NOT PLANNED ON, AND WHY.
 *
 * An ordinary completion line names a material the run expected. A line naming
 * something else — the 100 ml cartons ran out, so today's run went in a 90 ml
 * box — is an ADDED line, and it now carries its own reason and the person who
 * authorised it, on the row rather than in a note nobody reads.
 *
 * Nullable on purpose: every completion recorded before this migration was an
 * expected line, and null is the honest reading of "nobody added this".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->string('added_reason', 255)->nullable()->after('quantity_issued_kg');
            $table->foreignId('added_by')->nullable()->after('added_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropColumn('added_reason');
        });
    }
};
