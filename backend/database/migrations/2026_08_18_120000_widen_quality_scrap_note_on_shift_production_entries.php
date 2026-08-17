<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * quality_scrap_note: string(255) → text.
 *
 * WHY. The note the quality gate writes when it SKIPS a scrap receipt is
 * prose, and its longest form — "the configured scrap item '<name>' matches
 * no stock item by SKU or exact name — check production.scrap.rejected_item_sku;
 * this is a misconfiguration, not a decision. …" (ShiftProductionEntryService)
 * — is 300+ characters with a real name in it. The column was created at
 * 255 (2026_08_01_130000). sqlite, which the fast test leg runs on, does not
 * enforce a varchar length, so nothing noticed; MySQL in strict mode — the
 * live instance — refuses the UPDATE with 1406 "Data too long", the
 * transaction rolls back and the whole quality rejection answers 500. Found
 * by the MySQL leg added to ci.yml (Phase 7, P7-02): four suites 500 on the
 * rejection path there and pass on sqlite.
 *
 * WHAT THIS DOES NOT DO. It does not shorten the message: the note exists to
 * say out loud, on the approval screen, WHY no scrap weight was booked; a
 * cap that ate its tail would drop exactly the sentence that tells the
 * accountant the rejected bottles are already out of finished goods. And it
 * touches no row: existing notes are all ≤ 255 by construction and read back
 * unchanged.
 *
 * THE DDL THIS EMITS, checked through the MySQL schema grammar rather than
 * assumed (the test database is sqlite, whose change() rebuilds the table
 * and would hide a MySQL-only refusal):
 *
 *   up():   alter table `shift_production_entries`
 *             modify `quality_scrap_note` text null
 *   down(): alter table `shift_production_entries`
 *             modify `quality_scrap_note` varchar(255) null
 *
 * Nullability is preserved both ways; the column carries no index and no key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->text('quality_scrap_note')->nullable()->change();
        });
    }

    /**
     * Reversible in shape. A note longer than 255 written while the column
     * was text would be TRUNCATED by MySQL on the way back (or refused in
     * strict mode) — the same limit this migration exists to lift, stated
     * rather than hidden.
     */
    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->string('quality_scrap_note', 255)->nullable()->change();
        });
    }
};
