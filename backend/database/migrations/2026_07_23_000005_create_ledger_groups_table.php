<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tally ledger groups (the accounting-side hierarchy: e.g. Current Assets
        // → Sundry Debtors). Kept in the TallySync mirror, NOT the ERP's own
        // gl_accounts, so pulling a client's chart of accounts never disturbs the
        // app's native accounting. Same multi-level self-reference as item_groups.
        Schema::create('ledger_groups', function (Blueprint $table) {
            $table->id();
            $table->string('tally_guid')->nullable()->unique();
            $table->string('name');
            $table->string('tally_parent_name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('ledger_groups')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_groups');
    }
};
