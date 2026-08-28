<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A MIRRORED LEDGER LEARNS ITS PARTY DETAILS — so a vendor can be created from
 * one without a person retyping a GSTIN they already have in Tally.
 *
 * The mirror was built to back the Settings ledger pick-list, which needs only
 * a name and a group. Making a VENDOR out of a ledger needs more, and the two
 * fields a purchase order actually uses are the party's GSTIN and its state:
 * the state decides local versus interstate, which decides the purchase ledger
 * and whether the tax is CGST+SGST or IGST (DEC-20260812-003).
 *
 * Both nullable with no default, so every ledger already mirrored keeps the
 * honest answer "not pulled" rather than a backfill inventing one. Only the
 * masters sync writes them, and only from what Tally actually returned.
 *
 * `gstin` is 15 characters because that is what a GSTIN is. `state_name` holds
 * Tally's own spelling of the state, not a code — the ERP's two-digit GST
 * state code is a different thing and is not what Tally hands back here.
 *
 * Strictly additive. No column dropped, no value rewritten, and nothing
 * outside the TallySync mirror touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledgers', function (Blueprint $table): void {
            if (! Schema::hasColumn('ledgers', 'gstin')) {
                $table->string('gstin', 15)->nullable()->after('tally_group_name');
            }
            if (! Schema::hasColumn('ledgers', 'state_name')) {
                $table->string('state_name')->nullable()->after('gstin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledgers', function (Blueprint $table): void {
            foreach (['gstin', 'state_name'] as $column) {
                if (Schema::hasColumn('ledgers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
