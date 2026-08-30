<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An idempotency key for the store issue, exactly as goods receipts have
 * carried one since 2026_07_30_000001.
 *
 * WHAT THIS CLOSES. A store issue was the one stock writer of the three with
 * no guard at all. A goods receipt replays on `receipt_key`; a shift
 * production entry's completion does a compare-and-swap on `batch_status`
 * and refuses a second attempt. `StoreIssueService::issue()` simply created
 * the row, so two identical POSTs — a double-tap, a retried request after a
 * timeout, a browser resend — produced two store issues, two issue numbers,
 * and two recordTransfer pairs moving material into Production/WIP twice.
 * Nothing in the ledger looked wrong afterwards: both issues were real,
 * both balances were consistent, and the store had simply handed over twice
 * as much as it thought.
 *
 * NULLABLE, and that is deliberate. Every issue recorded before this
 * migration has no key and must keep none — backfilling one would invent an
 * identity for a handover nobody keyed. A caller that sends no key gets the
 * old behaviour, which keeps the API compatible for anything already
 * integrating; the FRONTEND always sends one, which is what makes the guard
 * real rather than optional. (The lots-block lesson from the 30-Aug audit:
 * an optional protection nobody exercises protects nothing.)
 *
 * TWO COLUMNS, not one. The key alone would let a retry with EDITED
 * quantities silently return the first issue and report success while
 * writing nothing — the worst possible answer, because the store would
 * believe the correction had been recorded. The payload hash makes that case
 * a refusal instead: same key + different data is a 422 telling the caller
 * to generate a new key.
 *
 * The unique index is what makes the guard hold under a genuine race, where
 * both requests miss the first read: one transaction wins, the other rolls
 * back on the constraint and returns the winner. Its name is well inside
 * MySQL's 64-character identifier limit, which sqlite would not have caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_issues', function (Blueprint $table) {
            $table->string('issue_key', 100)->nullable()->unique()->after('id');
            $table->char('issue_payload_hash', 64)->nullable()->after('issue_key');
        });
    }

    public function down(): void
    {
        Schema::table('store_issues', function (Blueprint $table) {
            $table->dropUnique(['issue_key']);
            $table->dropColumn(['issue_key', 'issue_payload_hash']);
        });
    }
};
