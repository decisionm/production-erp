<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Exceptions\ReceiptNoteNotPostable;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tally-sync.receipt_notes_enabled — the fail-closed reading of an OPEN
 * question. Whether the factory uses Tally Receipt Notes for GRN/inward at
 * all is PENDING Q63, unanswered since 26-Aug-2026: no export read so far
 * holds a GRN voucher sample, and no decision record settles it. Nothing
 * here asserts the owner has ruled. The application default (no env set)
 * is OFF; this suite pins it ON (phpunit.xml) purely so the rest of the
 * TallySync suite — written against the pre-existing, always-on contract —
 * keeps passing unmodified. This file is the one place both states are
 * exercised directly against the flag itself, and it never relies on that
 * pin: every test sets `tally-sync.receipt_notes_enabled` explicitly.
 *
 * THREE LOCKS, not one. (1) The listener: it never calls
 * enqueueGoodsReceiptNote() while the flag is off, so no NEW row is created
 * via today's one event path. (2) The service method ITSELF: it now checks
 * the same config first and throws ReceiptNoteNotPostable — a future or
 * direct caller (a new controller action, a console command, anything that
 * is not today's one gated listener) cannot create a row either. (3) inside
 * pending() (tested below, in "the second egress lock"): a Receipt Note row
 * that reached Pending some other way — it existed before the flag was
 * turned off, or a human pressed Retry on a failed one — is withheld from
 * the agent there too. Locks (1)/(2) refuse CREATION; lock (3) is the
 * egress lock for a row that exists despite them. None of the three
 * deletes or rewrites a row; each just declines to produce or hand out one.
 *
 * Deliberately builds the GoodsReceiptNote as an in-memory, unsaved model
 * (TransactionClassifierTest's `existing()` pattern) rather than through the
 * real HTTP endpoint for the listener tests — nothing there needs a fully
 * wired GRN. The pending()/retry() tests below go one step further and skip
 * the model + event entirely, writing the tally_sync_entries row directly,
 * because that is exactly what a row already sitting in the queue looks
 * like from those methods' point of view.
 */
class ReceiptNoteFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The phpunit.xml pin only supplies a value when the env var is actually
     * set; this reads config/tally-sync.php FRESH with the env var cleared
     * (unlike config('tally-sync.receipt_notes_enabled'), which would just
     * return the app's already-booted, pinned-ON value) — the same default a
     * server with no such line in .env would get.
     */
    public function test_the_config_default_is_off_when_no_env_is_set(): void
    {
        $name = 'TALLY_SYNC_RECEIPT_NOTES_ENABLED';
        $original = ['putenv' => getenv($name), 'env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];

        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        try {
            $fresh = require config_path('tally-sync.php');
            $this->assertFalse($fresh['receipt_notes_enabled'], 'a fresh deployment with no env line at all must default OFF');
        } finally {
            if ($original['putenv'] !== false) {
                putenv("{$name}={$original['putenv']}");
            }
            if ($original['env'] !== null) {
                $_ENV[$name] = $original['env'];
            }
            if ($original['server'] !== null) {
                $_SERVER[$name] = $original['server'];
            }
        }
    }

    public function test_with_the_flag_off_the_grn_event_stages_nothing(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);

        event(new GoodsReceiptNoteReceived($this->note()));

        $this->assertSame(0, TallySyncEntry::query()->count(), 'the listener no-ops rather than staging a Receipt Note');
    }

    public function test_with_the_flag_on_the_grn_event_stages_exactly_one_receipt_note(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true]);

        event(new GoodsReceiptNoteReceived($this->note()));

        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Receipt Note', $entry->tally_voucher_type);
    }

    /**
     * Lock (2): the service method itself, independent of the listener. A
     * future or direct caller — nothing today's suite exercises, which is
     * exactly why this needs its own guard rather than trusting the one
     * gated call site to be the only one forever.
     */
    public function test_a_direct_service_call_while_the_flag_is_off_creates_no_queue_row(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);

        try {
            app(TallySyncService::class)->enqueueGoodsReceiptNote($this->note());
            $this->fail('expected ReceiptNoteNotPostable');
        } catch (ReceiptNoteNotPostable $refusal) {
            $this->assertStringContainsString('receipt_notes_enabled = false', $refusal->getMessage());
        }

        $this->assertSame(0, TallySyncEntry::query()->count(), 'nothing is queued when called directly, flag or no listener');
    }

    public function test_a_direct_service_call_while_the_flag_is_on_stages_exactly_one_receipt_note(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true]);

        $entry = app(TallySyncService::class)->enqueueGoodsReceiptNote($this->note());

        $this->assertSame('Receipt Note', $entry->tally_voucher_type);
        $this->assertSame(1, TallySyncEntry::query()->count());
    }

    /* ── The second EGRESS lock: pending() withholds, it never deletes or alters ── */

    /**
     * A Receipt Note row that reached Pending BEFORE the flag was turned
     * off (or by any other means — the listener is not the only door: a
     * row already sitting in the queue does not care how it got there) must
     * not reach the agent while the flag is off. The row itself is left
     * completely alone: still Pending, delivered_at still null.
     */
    public function test_an_already_pending_receipt_note_is_withheld_from_the_agent_while_the_flag_is_off(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);
        $entry = $this->queuedReceiptNote();

        $offered = app(TallySyncService::class)->pending();

        $this->assertTrue($offered->pluck('id')->doesntContain($entry->id));
        $entry->refresh();
        $this->assertSame(TallySyncStatus::Pending, $entry->status, 'the row is withheld, not altered');
        $this->assertNull($entry->delivered_at, 'never stamped as delivered — it was never handed out');
    }

    public function test_the_same_row_is_offered_again_once_the_flag_is_turned_back_on(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);
        $entry = $this->queuedReceiptNote();
        app(TallySyncService::class)->pending();

        config(['tally-sync.receipt_notes_enabled' => true]);
        $offered = app(TallySyncService::class)->pending();

        $this->assertTrue($offered->pluck('id')->contains($entry->id));
        $this->assertNotNull($entry->refresh()->delivered_at);
    }

    /**
     * retry() re-queues a failed entry's FROZEN payload directly
     * (regeneratePayload() has no Receipt Note case, so the frozen payload
     * stands) — it never goes through the gated listener or re-checks this
     * config. Without the withhold in pending() this would be a live hole:
     * a human fixing "why did this fail" and pressing Retry would silently
     * re-arm a voucher type the factory has said it does not use.
     */
    public function test_a_retried_failed_receipt_note_is_withheld_from_the_agent_while_the_flag_is_off(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);
        $entry = $this->queuedReceiptNote(TallySyncStatus::Failed, ['error_message' => 'Synthetic failure — Ledger Alpha unmapped']);

        $retried = app(TallySyncService::class)->retry($entry);

        $this->assertSame(TallySyncStatus::Pending, $retried->status, 'retry() itself is not blocked — only the hand-out is');
        $offered = app(TallySyncService::class)->pending();
        $this->assertTrue($offered->pluck('id')->doesntContain($entry->id));
        $this->assertNull($entry->refresh()->delivered_at);
    }

    /**
     * The guard names 'Receipt Note' and nothing else: a Delivery Note (or
     * any other voucher type) queued the same way is untouched by this flag,
     * proving the withhold is narrow rather than a blanket pause on the
     * whole queue.
     */
    public function test_a_pending_delivery_note_is_unaffected_by_the_receipt_notes_flag(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);
        $entry = TallySyncEntry::create([
            'syncable_type' => 'App\\Modules\\Sales\\Models\\Delivery',
            'syncable_id' => 999999,
            'tally_voucher_type' => 'Delivery Note',
            'payload' => ['voucher_type' => 'Delivery Note', 'voucher_number' => 'DN-999'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);

        $offered = app(TallySyncService::class)->pending();

        $this->assertTrue($offered->pluck('id')->contains($entry->id));
    }

    /**
     * A raw queue row, exactly as it would sit in tally_sync_entries — no
     * event, no listener, so it exercises pending()/retry() directly. The
     * syncable id deliberately names no real row: regeneratePayload() reads
     * `$entry->syncable` (null for a non-existent id) and falls back to the
     * frozen payload, which is all a Receipt Note retry ever does.
     */
    private function queuedReceiptNote(TallySyncStatus $status = TallySyncStatus::Pending, array $overrides = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => (new GoodsReceiptNote)->getMorphClass(),
            'syncable_id' => 999998,
            'tally_voucher_type' => 'Receipt Note',
            'payload' => ['voucher_type' => 'Receipt Note', 'voucher_number' => 'GRN-999'],
            'status' => $status,
            'attempts' => 0,
        ], $overrides));
    }

    private function note(): GoodsReceiptNote
    {
        // Unmistakably synthetic (FC-06): no real vendor, GSTIN, item or
        // warehouse name — this test never touches the factory's data. The
        // Tally identities (ledger name, item guid, godown guid) are carried
        // because the enqueue refuses unmapped ones since the 28-Aug
        // rehearsal fix — this suite is about the FLAG, not the identities.
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => 'Vendor Alpha', 'gstin' => '00AAAAA0000A0Z0', 'tally_ledger_name' => 'Vendor Alpha']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'ITEM-A', 'name' => 'Item Alpha', 'tally_stock_item_guid' => 'itm-alpha']));
        $line->setRelation('scheduleAllocations', collect());

        $note = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-10']), 501);
        $note->setRelation('lines', collect([$line]));
        $note->setRelation('warehouse', new Warehouse(['name' => 'Warehouse Alpha', 'tally_guid' => 'gd-alpha']));
        $note->setRelation('purchaseOrder', $po);

        return $note;
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
