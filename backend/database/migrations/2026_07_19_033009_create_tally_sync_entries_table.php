<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_sync_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('syncable'); // the source document: Sales\Models\Invoice, Finance\Models\JournalEntry, ...
            $table->string('tally_voucher_type'); // e.g. 'Sales', 'Journal' — the Tally-side voucher type name
            $table->json('payload'); // XML-agnostic intermediate shape; the local agent translates to Tally's XML
            $table->string('status')->default('pending'); // pending | synced | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_sync_entries');
    }
};
