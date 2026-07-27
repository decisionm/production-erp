<?php

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business display sequence for machines — Machine 10 must sort after
 * Machine 9, which name-text ordering can never guarantee. Backfilled
 * from the numeric part of MC-xx codes; rows without one sort last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->unsignedSmallInteger('display_sequence')->nullable()->after('name');
        });

        WorkCenter::query()->each(function (WorkCenter $workCenter) {
            if (preg_match('/^MC-0*(\d+)$/i', $workCenter->code, $m)) {
                $workCenter->update(['display_sequence' => (int) $m[1]]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->dropColumn('display_sequence');
        });
    }
};
