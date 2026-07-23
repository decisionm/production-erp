<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "not hardcoded" heart of voucher building: instead of a literal
        // "Sales Account" baked into the code, each posting role maps to the Tally
        // ledger NAME this client actually uses. Set once in Settings (picked from
        // the pulled `ledgers` list), read by the voucher builders. A new client =
        // new rows here, zero code change.
        Schema::create('tally_ledger_mappings', function (Blueprint $table) {
            $table->id();
            // e.g. sales, purchase, cgst, sgst, igst, round_off, resin_consumption,
            // regrind_credit — the set grows as voucher types are added.
            $table->string('role')->unique();
            $table->string('tally_ledger_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_ledger_mappings');
    }
};
