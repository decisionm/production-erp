<?php

namespace App\Modules\TallySync\Providers;

use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Support\Facades\Event;
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

        Event::listen(ShiftProductionEntryApproved::class, function (ShiftProductionEntryApproved $event) {
            $this->app->make(TallySyncService::class)->enqueueShiftProductionEntry($event->entry);
        });
    }
}
