<?php

namespace App\Modules\TallySync\Providers;

use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Events\PurchaseOrderCancelled;
use App\Modules\Procurement\Events\PurchaseOrderClosed;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Exceptions\PurchaseOrderNotPostable;
use App\Modules\TallySync\Exceptions\ReceiptNoteNotPostable;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The only place Sales/Finance and TallySync meet. Registered here rather
 * than inside Invoice/JournalEntry themselves so those modules stay
 * completely unaware TallySync exists — this provider reaches out to them,
 * not the other way around. If TallySync were removed, neither module
 * would need to change.
 */
class TallySyncEventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Invoice::updated(function (Invoice $invoice) {
            if ($invoice->wasChanged('status') && $invoice->status === InvoiceStatus::Issued) {
                $this->app->make(TallySyncService::class)->enqueueSalesInvoice($invoice);
            }
        });

        JournalEntry::updated(function (JournalEntry $entry) {
            if ($entry->wasChanged('status') && $entry->status === JournalEntryStatus::Posted) {
                $this->app->make(TallySyncService::class)->enqueueJournalEntry($entry);
            }
        });

        // Inbound goods, outbound goods, and approved production. These use
        // explicit domain events (not model events) because the receipt/delivery
        // are posted at creation — after their lines exist — and production
        // approval is an atomic query update that fires no model event.
        // Gated on tally-sync.receipt_notes_enabled, OFF by default —
        // whether the factory uses Tally Receipt Notes at all is PENDING Q63
        // and unanswered, so off is the fail-closed reading of an open
        // question rather than a decision anyone has taken. OFF means this
        // stages nothing — no queue row, no XML, and nothing about a past
        // GRN or a past Receipt Note voucher is touched — and, like the PO
        // listener, what staging concluded is RECORDED on the receipt
        // (tally_staging) so the receiving desk reads the state instead of
        // silence. A refusal (an unmapped item, an unmapped vendor ledger,
        // no allowed company) is likewise recorded, never thrown onward:
        // the material HAS arrived and the stock HAS posted — an arrival
        // must not fail because Tally staging refused.
        Event::listen(GoodsReceiptNoteReceived::class, function (GoodsReceiptNoteReceived $event) {
            $this->stageGoodsReceiptNote($event->note);
        });

        Event::listen(DeliveryDispatched::class, function (DeliveryDispatched $event) {
            $this->app->make(TallySyncService::class)->enqueueDelivery($event->delivery);
        });

        // A purchase order sent to its vendor → Tally Purchase Order voucher,
        // STAGED and OWNER-GATED (Phase 6, DEC-20260812-002, Q35). Unlike its
        // neighbours this listener never lets an exception out of send():
        // the order IS sent whether or not Tally can be staged, and what
        // Tally-staging concluded is RECORDED on the order (tally_staging —
        // PurchaseOrderService::recordTallyStaging, the only writer):
        //   flag off  → {state: 'disabled'} — nothing enqueued, one debug line
        //   flag on   → enqueuePurchaseOrder(): {state: 'enqueued', entry_id}
        //               or, refused with named reasons, {state: 'refused',
        //               reasons} — a missing Tally name is never guessed.
        Event::listen(PurchaseOrderSent::class, function (PurchaseOrderSent $event) {
            $this->stagePurchaseOrder($event->order);
        });

        // The same order CANCELLED or SHORT-CLOSED afterwards. Whether the
        // staged voucher can still be taken back depends on one thing only:
        // has the agent collected it? See withdrawStagedPurchaseOrder().
        Event::listen(PurchaseOrderCancelled::class, function (PurchaseOrderCancelled $event) {
            $this->withdrawStagedPurchaseOrder($event->order, 'cancelled');
        });

        Event::listen(PurchaseOrderClosed::class, function (PurchaseOrderClosed $event) {
            $this->withdrawStagedPurchaseOrder($event->order, 'closed');
        });

        Event::listen(ShiftProductionEntryApproved::class, function (ShiftProductionEntryApproved $event) {
            $this->app->make(TallySyncService::class)->enqueueShiftProductionEntry($event->entry);
        });

        // The reverse hop: when the agent acks/fails a production voucher,
        // reflect that on the entry itself (approved → synced/failed, with
        // failed → synced recovery on a successful retry) so the approval
        // queue shows real sync state. Only ShiftProductionEntry carries
        // sync state on the source row — Invoice/JournalEntry deliberately
        // don't.
        TallySyncEntry::updated(function (TallySyncEntry $syncEntry) {
            if (! $syncEntry->wasChanged('status')) {
                return;
            }

            // Shift-granularity vouchers (voucher_granularity = 'shift'):
            // the morph names the Shift, and the member entries hang off
            // shift_production_entries.tally_sync_entry_id — fan the
            // ack/fail out to every member. A no-op (empty result) for
            // every batch-granularity voucher.
            $members = ShiftProductionEntry::query()
                ->where('tally_sync_entry_id', $syncEntry->id)
                ->get();

            if ($members->isNotEmpty()) {
                $service = $this->app->make(ShiftProductionEntryService::class);

                foreach ($members as $member) {
                    match ($syncEntry->status) {
                        TallySyncStatus::Synced => $service->markSynced($member),
                        TallySyncStatus::Failed => $service->markSyncFailed($member),
                        default => null,
                    };
                }

                return;
            }

            if ($syncEntry->syncable_type !== (new ShiftProductionEntry)->getMorphClass()) {
                return;
            }

            $entry = $syncEntry->syncable;

            if (! $entry instanceof ShiftProductionEntry) {
                return;
            }

            $service = $this->app->make(ShiftProductionEntryService::class);

            match ($syncEntry->status) {
                TallySyncStatus::Synced => $service->markSynced($entry),
                TallySyncStatus::Failed => $service->markSyncFailed($entry),
                default => null,
            };
        });
    }

    /**
     * The GoodsReceiptNoteReceived listener's body — stagePurchaseOrder()'s
     * shape on the receipt. Every branch ends in ONE recordTallyStaging()
     * call with the state the receipt should show; the enqueue itself never
     * touches the receipt.
     */
    private function stageGoodsReceiptNote(GoodsReceiptNote $note): void
    {
        $at = now()->toIso8601String();
        $receipts = $this->app->make(GoodsReceiptService::class);

        if (! config('tally-sync.receipt_notes_enabled')) {
            Log::debug('Goods receipt received; Tally Receipt Note staging disabled (tally-sync.receipt_notes_enabled = false — PENDING Q63).', [
                'goods_receipt_note_id' => $note->id,
            ]);
            $receipts->recordTallyStaging($note, [
                'state' => 'disabled',
                'reasons' => [[
                    'code' => 'receipt_notes_disabled',
                    'detail' => 'Receipt Note posting to Tally is off (whether the factory books Tally Receipt Notes is open — Q63). Nothing was staged.',
                ]],
                'at' => $at,
            ]);

            return;
        }

        try {
            $entry = $this->app->make(TallySyncService::class)->enqueueGoodsReceiptNote($note);
        } catch (ReceiptNoteNotPostable $refusal) {
            Log::info('Goods receipt not staged for Tally — refused with named reasons.', [
                'goods_receipt_note_id' => $note->id,
                'reasons' => $refusal->codes(),
            ]);
            $receipts->recordTallyStaging($note, [
                'state' => 'refused',
                'reasons' => $refusal->reasons,
                'at' => $at,
            ]);

            return;
        }

        $receipts->recordTallyStaging($note, [
            'state' => 'enqueued',
            'reasons' => [],
            'entry_id' => $entry->id,
            'at' => $at,
        ]);
    }

    /**
     * The PurchaseOrderSent listener's body — see the Event::listen above.
     * Every branch ends in ONE recordTallyStaging() call with the state the
     * order should show; the enqueue itself never touches the order.
     */
    private function stagePurchaseOrder(PurchaseOrder $order): void
    {
        $at = now()->toIso8601String();

        if (! config('tally-sync.purchase_orders_enabled')) {
            Log::debug('Purchase order sent; Tally staging disabled (tally-sync.purchase_orders_enabled = false, owner gate Q35).', [
                'purchase_order_id' => $order->id,
            ]);
            $this->recordTallyStaging($order, [
                'state' => 'disabled',
                'reasons' => [[
                    'code' => 'purchase_orders_disabled',
                    'detail' => 'PO posting to Tally is disabled (owner gate Q35) — nothing was staged.',
                ]],
                'at' => $at,
            ]);

            return;
        }

        try {
            $entry = $this->app->make(TallySyncService::class)->enqueuePurchaseOrder($order);
        } catch (PurchaseOrderNotPostable $refusal) {
            Log::info('Purchase order not staged for Tally — refused with named reasons.', [
                'purchase_order_id' => $order->id,
                'reasons' => $refusal->codes(),
            ]);
            $this->recordTallyStaging($order, [
                'state' => 'refused',
                'reasons' => $refusal->reasons,
                'at' => $at,
            ]);

            return;
        }

        $this->recordTallyStaging($order, [
            'state' => 'enqueued',
            'reasons' => [],
            'entry_id' => $entry->id,
            'at' => $at,
        ]);
    }

    /**
     * The PurchaseOrderCancelled / PurchaseOrderClosed listeners' body — an
     * order that stopped existing, and the Purchase Order voucher it staged.
     *
     * ONE question decides everything: has the agent collected the row?
     *   still Pending, delivered_at NULL → the queue row is OURS and nobody
     *     has ever seen it: it is WITHDRAWN through the same write-off
     *     dismiss() performs (TallySyncService::withdrawUncollected — status
     *     Dismissed, a resolution_log line, a voucher.dismissed history row)
     *     and the order records state 'dismissed' with the named reason.
     *   delivered / synced / failed → the entry is left EXACTLY as it is
     *     and only the order is annotated: its existing staging keys stay,
     *     plus `after` = cancelled_after_delivery | closed_after_delivery.
     *   no entry at all (the flag is off — the normal case today) → nothing
     *     new is recorded; tally_staging keeps whatever send() left there.
     *
     * WHAT IS DELIBERATELY NOT BUILT: the TALLY side of a cancelled or
     * closed order — an Alter voucher that shortens it, or a Cancel voucher
     * that kills it. That is OWNER QUESTION Q48 (docs/factory/
     * PENDING-OWNER-QUESTIONS.md), pending; nothing here guesses an answer,
     * and a voucher Tally may already hold is never silently rewritten.
     *
     * Like its PurchaseOrderSent sibling this listener never throws out of
     * cancel() / close(): the order IS cancelled or closed whether or not
     * the queue could be tidied, so anything unexpected is logged, not
     * raised.
     *
     * @param  'cancelled'|'closed'  $ending  how the order ended
     */
    private function withdrawStagedPurchaseOrder(PurchaseOrder $order, string $ending): void
    {
        try {
            $entry = TallySyncEntry::query()
                ->where('syncable_type', $order->getMorphClass())
                ->where('syncable_id', $order->getKey())
                ->where('tally_voucher_type', 'Purchase Order')
                ->where('status', '!=', TallySyncStatus::Dismissed->value)
                ->orderByDesc('id')
                ->first();

            if ($entry === null) {
                // Nothing was ever staged for this order (or it is already
                // written off). There is no Tally fact to record, so the
                // order's staging is left untouched — an unchanged column
                // is the honest answer, not a re-stamped one.
                return;
            }

            $withdrawn = $this->app->make(TallySyncService::class)->withdrawUncollected(
                $entry,
                reasonCode: "{$ending}_before_delivery",
                note: $ending === 'cancelled'
                    ? 'Withdrawn — the purchase order was cancelled before the agent collected it; never sent to Tally.'
                    : 'Withdrawn — the purchase order was short-closed before the agent collected it; never sent to Tally.',
            );

            if ($withdrawn !== null) {
                $this->recordTallyStaging($order, [
                    'state' => 'dismissed',
                    'reasons' => [[
                        'code' => "{$ending}_before_delivery",
                        'detail' => "The order was {$ending} before the agent collected it — the staged Purchase Order voucher was withdrawn and never sent to Tally.",
                    ]],
                    'entry_id' => $entry->id,
                    'at' => now()->toIso8601String(),
                ]);

                return;
            }

            // The agent holds it (or Tally already does). The entry stands;
            // the order simply says what happened to it afterwards, keeping
            // every key send() wrote — including the `at` of the staging
            // itself, which is still when the staging happened.
            $staging = $order->fresh()?->tally_staging ?? [];

            $this->recordTallyStaging($order, [
                'state' => (string) ($staging['state'] ?? 'enqueued'),
                'reasons' => $staging['reasons'] ?? [],
                'entry_id' => (int) ($staging['entry_id'] ?? $entry->id),
                'at' => isset($staging['at']) ? (string) $staging['at'] : now()->toIso8601String(),
                'after' => "{$ending}_after_delivery",
            ]);
        } catch (Throwable $error) {
            // A tidy-up that failed must not undo a cancellation that
            // already committed.
            Log::error('Could not settle the staged Tally voucher for a purchase order that was '.$ending.'.', [
                'purchase_order_id' => $order->id,
                'exception' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Cross-module write through Procurement's SERVICE, never its model
     * (CLAUDE.md). PurchaseOrderService::recordTallyStaging() is the ONE
     * writer of the additive `purchase_orders.tally_staging` JSON column
     * (Phase 6); this provider only tells it what happened.
     *
     * @param  array{state: string, reasons: list<array{code: string, detail: string}>, entry_id?: int, at: string, after?: string}  $staging
     */
    private function recordTallyStaging(PurchaseOrder $order, array $staging): void
    {
        $this->app->make(PurchaseOrderService::class)->recordTallyStaging($order, $staging);
    }
}
