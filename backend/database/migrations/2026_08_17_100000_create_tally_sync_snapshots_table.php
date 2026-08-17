<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the agent SENT to Tally and what Tally ANSWERED, one row per post
 * (Phase 4 — MASTER-PLAN P4-01..05; TALLY-SYNC-CHAIN.md §3).
 *
 * The cloud never builds the voucher XML and never contacts Tally: the
 * on-prem tally-sync-agent builds the XML from the normalized payload,
 * posts it to Tally on 127.0.0.1:9000, and reports ack/fail. Until this
 * table the cloud had no record of WHAT XML was posted nor of Tally's
 * answer beyond the one-line error_message. After each post the agent now
 * uploads a snapshot — the XML, its sha256, and Tally's response summary —
 * fire-and-forget: an upload that fails changes nothing about the post,
 * the ack, the fail, the status or the attempt count. This is a RECORD
 * kept beside the entry, not a second sync model: the agent reads nothing
 * here and no status is derived from these rows.
 *
 * FC-06 ON THIS TABLE. The FULL XML is stored (the same exposure class as
 * the payload the cloud already holds — a Receipt Note's XML carries the
 * supplier's ledger name, GSTIN, RATE and AMOUNT) and is SHOWN only to a
 * reader for whom AgentIdentity::mayReadPurchaseDetails() is true, or for
 * a Stock Journal (rate-free and party-free by construction). Tally's own
 * response text follows the same rule as error_message. The gate lives in
 * TallySyncSnapshotResource; this table stores what was sent, whole.
 *
 * NEVER EDITED. A new attempt is a new row; the model refuses update in
 * code (TallySyncSnapshot::booted). There is a created_at and no
 * updated_at, the tally_sync_events precedent. Unlike events, rows here
 * ARE deleted — by retention only (config tally-sync.snapshot_retention_days,
 * pruned opportunistically on write since the host has no scheduler): an
 * XML body is bulk, not history; the history row (snapshot.stored, with
 * the sha256 and counts) outlives it on tally_sync_events.
 *
 * Both drivers: sqlite in tests, MySQL live — mediumText for the XML (a
 * voucher can run past 64 KB; the request caps it at 2 MB), no
 * driver-specific SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_sync_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tally_sync_entry_id')
                ->constrained('tally_sync_entries')->cascadeOnDelete();
            // The entry's `attempts` as the agent saw it at report time —
            // or the cloud's current count when the agent sent none.
            $table->unsignedSmallInteger('attempt')->default(0);
            // 'post' — one row per post report. Kept as a column so a
            // future 'read' (a Tally-side read the agent might one day
            // report) has somewhere to land without a schema change.
            $table->string('direction', 8)->default('post');
            // sha256 of the XML bytes the agent posted, lowercase hex —
            // ALWAYS present, even when the body below is not, so anyone
            // can say "this exact document was sent" without holding it.
            $table->char('xml_sha256', 64);
            // Byte size of that XML — measured by the server when the body
            // was uploaded, else as the agent reported it; NULL when the
            // agent uploaded neither (over the cap, no size sent): a size
            // nobody measured is reported missing, never as 0.
            $table->unsignedInteger('xml_bytes')->nullable();
            // The XML itself; NULL when the agent uploaded no body (it omits
            // the body over 2 MB and still sends the sha).
            $table->mediumText('xml')->nullable();
            // Tally's answer as the agent summarised it: success, the
            // CREATED / ERRORS counts, its message text, and the raw response
            // (capped at 64 KB by the request). All NULL on the
            // inconclusive-timeout path — XML sent, no answer — which is a
            // record worth keeping in its own right.
            $table->boolean('tally_success')->nullable();
            $table->unsignedSmallInteger('tally_created')->nullable();
            $table->unsignedSmallInteger('tally_errors')->nullable();
            $table->text('tally_message')->nullable();
            $table->text('tally_raw')->nullable();
            // Which agent build posted it — from the agent's package.json.
            $table->string('agent_version', 32)->nullable();
            // The payload_hash the cloud stamped on the /pending row the agent
            // built from (PayloadHash::of), echoed back; and the verdict,
            // judged ON STORE against the payload the cloud holds NOW —
            // false means the payload was regenerated (a retry) after this
            // XML was built from it. NULL when the agent echoed no hash.
            $table->char('payload_hash', 64)->nullable();
            $table->boolean('payload_matches')->nullable();
            // created_at only — never edited (class docblock).
            $table->timestamp('created_at')->nullable();

            // "This entry's snapshots, newest first" is the read (and the
            // idempotency window's lookup); the retention prune reads
            // created_at alone. Explicitly named: MySQL rejects identifiers
            // over 64.
            $table->index(['tally_sync_entry_id', 'created_at'], 'tally_sync_snapshots_entry_time_index');
            $table->index('created_at', 'tally_sync_snapshots_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_sync_snapshots');
    }
};
