<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tally ledgers (leaves under a ledger group), mirrored for reference and
        // for the Settings pick-lists that drive tally_ledger_mappings. A ledger's
        // "parent" is its ledger group (not another ledger), so it links out to
        // ledger_groups rather than self-referencing. tally_group_name is kept so
        // the link re-resolves by name on every pull.
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('tally_guid')->nullable()->unique();
            $table->string('name');
            $table->string('tally_group_name')->nullable();
            $table->foreignId('ledger_group_id')->nullable()->constrained('ledger_groups')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
