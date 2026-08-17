<?php

namespace App\Modules\TallySync\Providers;

use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Exceptions\PurchaseOrderNotPostable;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

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
        Event::listen(GoodsReceiptNoteReceived::class, function (GoodsReceiptNoteReceived $event) {
            $this->app->make(TallySyncService::class)->enqueueGoodsReceiptNote($event->note);
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
     * Cross-module write through Procurement's SERVICE, never its model
     * (CLAUDE.md). PurchaseOrderService::recordTallyStaging() is WS-A's
     * (Phase 6, additive `purchase_orders.tally_staging` JSON); until it
     * lands, the outcome is logged and nothing is written — the enqueue /
     * refusal above has already happened either way.
     *
     * @param  array{state: string, reasons: list<array{code: string, detail: string}>, entry_id?: int, at: string}  $staging
     */
    private function recordTallyStaging(PurchaseOrder $order, array $staging): void
    {
        $service = $this->app->make(PurchaseOrderService::class);

        if (! method_exists($service, 'recordTallyStaging')) {
            Log::warning('PurchaseOrderService::recordTallyStaging() is not available — Tally staging outcome not recorded on the order.', [
                'purchase_order_id' => $order->id,
                'state' => $staging['state'],
            ]);

            return;
        }

        $service->recordTallyStaging($order, $staging);
    }
}
