<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tally godowns map onto our existing warehouses. Same self-referencing
        // parent_id so nested godowns (a Tally feature) survive with no fixed
        // depth. Matched on tally_guid, like every other Tally-sourced master.
        // Plain nullable parent_id (no DB-level FK): adding a constraint to an
        // existing table isn't portable to SQLite (test suite). The Warehouse
        // self-relation works without it; godown writes go through WarehouseService.
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('tally_guid')->nullable()->unique()->after('code');
            $table->string('tally_parent_name')->nullable()->after('name');
            $table->foreignId('parent_id')->nullable()->after('tally_parent_name');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique(['tally_guid']);
            $table->dropColumn(['tally_guid', 'tally_parent_name', 'parent_id']);
        });
    }
};
