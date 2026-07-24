<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tally stock groups, mirrored. Multi-level with zero level assumptions:
        // parent_id points back at this same table, so any depth of nesting for
        // any client works without a schema change (config-driven, not hardcoded).
        // tally_parent_name is kept so the parent link can be (re)resolved by name
        // on every pull, regardless of the order groups arrive in.
        Schema::create('item_groups', function (Blueprint $table) {
            $table->id();
            $table->string('tally_guid')->nullable()->unique();
            $table->string('name');
            $table->string('tally_parent_name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('item_groups')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Plain nullable FK column (no DB-level constraint): adding a constraint
        // to an existing table isn't portable to SQLite, which the test suite
        // uses. The Eloquent relation (Item::group) doesn't need the DB FK; item
        // writes are all funnelled through ItemService, which sets this safely.
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('item_group_id')->nullable()->after('tally_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('item_group_id');
        });

        Schema::dropIfExists('item_groups');
    }
};
