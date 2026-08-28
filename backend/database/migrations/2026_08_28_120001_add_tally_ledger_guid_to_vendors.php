<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH TALLY LEDGER A VENDOR IS — the identity half of the mapping.
 *
 * `vendors.tally_ledger_name` already exists and is free text Accounts types
 * in. It answers "what should a voucher call this party", and it is the wrong
 * thing to key an import on: a person may rename it, and two ledgers may share
 * a name across companies. The customer master learned this first and carries
 * both halves (`tally_ledger_guid` + `tally_ledger_name`, migration
 * 2026_08_26_110000); this gives the vendor master the same.
 *
 * DELIBERATELY ABSENT FROM Vendor's #[Fillable]. The import command is its only
 * writer, through forceFill, so no request, no vendor form and no future
 * `Vendor::create([...$input])` can quietly point a vendor at a different Tally
 * ledger. That protection is the reason the column exists rather than the name
 * being trusted.
 *
 * Nullable with no default: every vendor that exists now keeps the honest
 * answer "not linked", and a vendor created by hand on the form never gets one.
 * Additive only — nothing is dropped, rewritten or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            if (! Schema::hasColumn('vendors', 'tally_ledger_guid')) {
                $table->string('tally_ledger_guid')->nullable()->after('tally_ledger_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            if (Schema::hasColumn('vendors', 'tally_ledger_guid')) {
                $table->dropColumn('tally_ledger_guid');
            }
        });
    }
};
