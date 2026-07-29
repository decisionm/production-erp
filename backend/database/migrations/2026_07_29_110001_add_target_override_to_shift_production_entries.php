<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audited replacement for the workbook's hidden "+1".
 *
 * At least 18 estimation cells in the factory's sheet carry a manual
 * `ROUND(...) + 1` — an adjustment with no recorded author, reason or
 * visibility. Whoever reads the sheet later cannot tell an adjusted target
 * from a computed one.
 *
 * Here the computed target is always kept, the override sits beside it, and
 * a reason is mandatory. Approval shows both, so an adjusted target is an
 * argument someone made rather than a number that quietly changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->unsignedInteger('target_boxes_override')->nullable()->after('scheduled_hours');
            $table->text('target_override_reason')->nullable()->after('target_boxes_override');
            $table->foreignId('target_override_by')->nullable()->after('target_override_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_override_by');
            $table->dropColumn(['target_boxes_override', 'target_override_reason']);
        });
    }
};
