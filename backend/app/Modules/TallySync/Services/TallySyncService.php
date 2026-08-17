<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Services\ScrapItemResolver;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Exceptions\PurchaseOrderNotPostable;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds an XML-agnostic intermediate payload per voucher and queues it —
 * never talks to Tally directly (there is no cloud-to-Tally connection;
 * see TECHNICAL-DOCS.md §6). Payloads carry human-readable ledger/party
 * names rather than internal IDs, since Tally voucher import matches
 * against ledger names already configured in the customer's own Tally
 * company, not our database IDs.
 */
class TallySyncService
{
    /**
     * Every godown name written into a payload goes through $godowns
     * (TallyGodownResolver): an internal warehouse Tally has never heard of
     * (the factory day bin) posts under its parent godown's name — or, in
     * this one-godown factory, under the single Tally-linked godown — so
     * the accountant's books keep seeing exactly the godowns Tally knows.
     */
    public function __construct(
        private readonly TallyLedgerMappingService $ledgerMappings,
        private readonly TallyGodownResolver $godowns,
        private readonly PackingVoucherLines $packing,
        private readonly ShiftVoucherReleaseGate $releaseGate,
        // The ONE scrap-item lookup, shared with the quality gate's scrap
        // receipt so the voucher and the ERP's own stock can never name
        // different scrap items.
        private readonly ScrapItemResolver $scrapItems,
        // The append-only history (tally_sync_events). Every mutation below
        // records itself AFTER the state change lands, and the paths that
        // hold a transaction record INSIDE it — so the history never claims
        // a thing that rolled back, and never loses one that committed.
        // Transactional: enqueueShiftVoucher() (enqueue/merge/follow-up +
        // their events), pending() (read → stamp → pending.delivered), and
        // retry() (regenerate → update → voucher.rebuilt/voucher.retried).
        // NOT transactional, by design: markSynced()/markFailed()/dismiss()/
        // releaseNow() — one UPDATE then one INSERT, and enqueue() for the
        // batch/sales/GRN/PO/delivery/journal paths (one SELECT for a live
        // entry to return, else one INSERT then one INSERT); a failure
        // between the two writes there leaves the state change in place and
        // the event missing, never the reverse.
        private readonly TallySyncEventRecorder $events,
    ) {}

    public function enqueueSalesInvoice(Invoice $invoice): TallySyncEntry
    {
        $invoice->loadMissing(['lines.item', 'customer']);

        $lines = $invoice->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
            'rate' => $line->unit_price,
            'amount' => bcmul($line->quantity, $line->unit_price, 4),
        ])->all();

        $totalAmount = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 4), '0.0000');

        return $this->enqueue($invoice, 'Sales', [
            'voucher_type' => 'Sales',
            'voucher_date' => $invoice->invoice_date?->toDateString(),
            'voucher_number' => "INV-{$invoice->id}",
            'party_ledger' => $invoice->customer->name,
            'party_gstin' => $invoice->customer->gstin,
            // The configured Sales ledger (Settings → Ledger Mappings) instead of
            // a hardcoded "Sales Account", so each client posts to their own.
            'sales_ledger' => $this->ledgerMappings->get(TallyLedgerRole::Sales),
            'narration' => $invoice->notes,
            'lines' => $lines,
            'total_amount' => $totalAmount,
        ]);
    }

    public function enqueueJournalEntry(JournalEntry $entry): TallySyncEntry
    {
        $entry->loadMissing('lines.glAccount');

        $lines = $entry->lines->map(fn ($line) => [
            'ledger' => "{$line->glAccount->code} - {$line->glAccount->name}",
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $line->memo,
        ])->all();

        return $this->enqueue($entry, 'Journal', [
            'voucher_type' => 'Journal',
            'voucher_date' => $entry->entry_date?->toDateString(),
            'voucher_number' => $entry->reference ?? "JE-{$entry->id}",
            'narration' => $entry->memo,
            'lines' => $lines,
        ]);
    }

    /**
     * Raw material received against a PO → Tally Receipt Note (increases RM
     * stock). Party is the supplier; godown is the receiving warehouse. The
     * agent translates this to the Receipt Note voucher XML.
     */
    public function enqueueGoodsReceiptNote(GoodsReceiptNote $note): TallySyncEntry
    {
        $note->loadMissing(['lines.item', 'lines.scheduleAllocations.schedule', 'warehouse', 'purchaseOrder.vendor']);

        $lines = $note->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
            'rate' => $line->unit_cost,
            'amount' => bcmul($line->quantity, $line->unit_cost, 4),
        ])->all();

        $totalAmount = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 4), '0.0000');

        return $this->enqueue($note, 'Receipt Note', [
            'voucher_type' => 'Receipt Note',
            'voucher_date' => $note->received_date?->toDateString(),
            'voucher_number' => $note->receipt_note_reference ?? "GRN-{$note->id}",
            'party_ledger' => $note->purchaseOrder?->vendor?->name,
            'party_gstin' => $note->purchaseOrder?->vendor?->gstin,
            'godown' => $this->godowns->resolveName($note->warehouse),
            'narration' => $note->notes,
            'lines' => $lines,
            'total_amount' => $totalAmount,
            // ADDITIVE order identities (owner flow: Tally is the PO/schedule
            // master; the arrival must reference the exact Tally order and
            // carry a unique tracking number so Tally can clear the right
            // outstanding allocation). The XML builder consumes these only
            // once the real Receipt Note shape is proven — until then they
            // ride the payload as recorded facts.
            'tracking_number' => $note->tracking_number,
            'tally_order_no' => $note->purchaseOrder?->tally_order_no,
            'order_due_dates' => $note->lines
                ->flatMap(fn ($line) => $line->scheduleAllocations->map(fn ($allocation) => [
                    'due_date' => $allocation->schedule?->due_date?->toDateString(),
                    'quantity' => $allocation->quantity,
                    'tally_reference' => $allocation->schedule?->tally_reference,
                ]))
                ->filter(fn ($row) => $row['due_date'] !== null)
                ->values()
                ->all(),
        ]);
    }

    /**
     * An ERP-raised purchase order, sent to its vendor → Tally 'Purchase
     * Order' voucher — STAGED (Phase 6, DEC-20260812-002: "purchase orders
     * are raised in the ERP and sent to Tally as a Purchase Order voucher;
     * it must not touch accounts or stock in Tally").
     *
     * WHAT THIS WRITES: ONE tally_sync_entries row (voucher type 'Purchase
     * Order') and its voucher.enqueued event — nothing else. No stock
     * movement, no stock balance, no material lot, no journal: an ORDER is a
     * promise, and Tally treats it as one BECAUSE OF THE VOUCHER TYPE
     * (VCHTYPE 'Purchase Order', ISINVOICE No), not because any ledger block
     * is left out of the XML — the real exports carry the party and purchase
     * ledgers on every order and still move no stock. The agent
     * (purchaseOrder.ts, ≥ 0.3.9) builds that shape; PurchaseOrderTallyStagingTest
     * counts the tables before and after to prove the cloud half.
     *
     * OWNER GATE. tally-sync.purchase_orders_enabled is OFF by default and the
     * first live PO write to real Tally is the owner's call (Q35(d)); this
     * method refuses while the flag is off, so no caller — the listener, a
     * command, a test that forgot config() — can stage one unnoticed. The
     * listener (TallySyncEventServiceProvider) checks the flag itself first
     * and records 'disabled' on the order without calling here.
     *
     * NAMES ARE NEVER INVENTED (DEC-20260812-002 (iii)). Every Tally name the
     * voucher carries is a RECORDED identity, and a missing one is a REFUSAL
     * with a named reason (PurchaseOrderNotPostable), never a guess and never
     * a default:
     *   party ledger     vendors.tally_ledger_name (typed by Accounts; nothing
     *                    populates it from Tally)              → 'party_unmapped'
     *   purchase ledger  TallyLedgerRole::Purchase via the ledger mappings
     *                    (Settings → Ledger Mappings) — ONE role for now, Q39
     *                    (a ledger per rate) pending; NOT an env key → 'purchase_ledger_unmapped'
     *   stock item       the item's exact Tally name, only for a Tally-sourced
     *                    item (Item::isTallySourced — GUID recorded by the
     *                    masters pull) that is not a local fixture → 'item_unmapped'
     *   godown           the ONE Tally-linked warehouse (TallyGodownResolver::
     *                    soleTallyGodownName; a PO has no receiving store of
     *                    its own until its GRN)                  → 'godown_unresolved'
     *   lines            none                                    → 'no_lines'
     * All reasons are collected before refusing, so the order's tally_staging
     * names everything to fix at once. This method never writes to the order
     * itself — it returns the entry or throws — so a direct caller sees the
     * pure contract; the LISTENER records the outcome on the order through
     * PurchaseOrderService::recordTallyStaging().
     *
     * PAYLOAD (the GRN's key names, followed for the same reasons —
     * TransactionClassifier reads them):
     *   voucher_type · voucher_date (order_date, ISO — the agent writes
     *   yyyymmdd) · voucher_number "PO-{id}" (the ERP reference, the same
     *   convention GRN-{id} uses; Q35(c) — whose number is authoritative —
     *   is PENDING and may change this before the first live write) ·
     *   voucher_number_source 'erp' (tally_order_no is a MIRROR's field and
     *   is never used for an ERP-raised order) · party_ledger · party_gstin ·
     *   purchase_ledger · godown · reference (the same ERP reference — on the
     *   real exports REFERENCE equals VOUCHERNUMBER) · narration (notes) ·
     *   lines[]{item, quantity, rate, amount, schedules[]{due_date, quantity,
     *   amount}} · total_amount.
     *   schedules[] mirror PurchaseOrderSchedule (Tally's ORDERDUEDATE
     *   allocations, one BATCHALLOCATIONS each), each at its own quantity ×
     *   rate; a line whose schedules promise LESS than the line gets one
     *   more REMAINDER allocation (quantity = line − Σ schedules, due_date =
     *   the order's expected_date or null); a line with no schedule gets ONE
     *   allocation for the whole line dated the order's expected_date, or
     *   none at all when there is no expected date — the agent emits no
     *   ORDERDUEDATE for a null due date rather than a made-up one. The last
     *   allocation's amount is the remainder, so allocation quantities and
     *   amounts always sum to the line exactly (docs/tally-sync/
     *   PO-VOUCHER-CONTRACT.md §4). NO `unit`: Item.uom is Tally's base unit at the
     *   last pull but is user-editable and carries no provenance, so the ERP
     *   cannot say it IS the Tally symbol (Q40) — the agent emits bare
     *   decimals, the form the live Stock Journals already post with.
     *   Signs (negative inventory / positive party) are the AGENT's business
     *   (measured on the exports; purchaseOrder.ts docblock) — the payload
     *   carries plain positive figures like every other builder's.
     *
     * FC-06: rate/amount (line AND schedule) and party_ledger/party_gstin ride
     * TallySyncEntryResource's existing gate — 'Purchase Order' is a
     * supplier-party category (TallyTransactionCategory::partyIsSupplier).
     * Idempotent per (order, 'Purchase Order') through enqueue().
     */
    public function enqueuePurchaseOrder(PurchaseOrder $order): TallySyncEntry
    {
        $order->loadMissing(['lines.item', 'lines.schedules', 'vendor']);

        $reasons = [];

        if (! config('tally-sync.purchase_orders_enabled')) {
            $reasons[] = [
                'code' => 'purchase_orders_disabled',
                'detail' => 'PO posting to Tally is disabled (tally-sync.purchase_orders_enabled = false; owner gate Q35).',
            ];
        }

        // vendors.tally_ledger_name — additive column typed by Accounts;
        // reads null (never throws) when it is not there.
        $partyLedger = $order->vendor?->tally_ledger_name;
        $partyLedger = is_string($partyLedger) && trim($partyLedger) !== '' ? trim($partyLedger) : null;
        if ($partyLedger === null) {
            $reasons[] = [
                'code' => 'party_unmapped',
                'detail' => $order->vendor === null
                    ? 'the order names no vendor.'
                    : "vendor #{$order->vendor->id} has no Tally ledger name recorded (vendors.tally_ledger_name).",
            ];
        }

        $purchaseLedger = $this->ledgerMappings->get(TallyLedgerRole::Purchase);
        if ($purchaseLedger === null) {
            $reasons[] = [
                'code' => 'purchase_ledger_unmapped',
                'detail' => "no Tally ledger is mapped to the '".TallyLedgerRole::Purchase->value."' role (Settings → Ledger Mappings).",
            ];
        }

        if ($order->lines->isEmpty()) {
            $reasons[] = ['code' => 'no_lines', 'detail' => 'the order has no lines.'];
        }

        foreach ($order->lines as $line) {
            $item = $line->item;
            if ($item === null || ! $item->isTallySourced() || $item->isLocalFixture()) {
                $reasons[] = [
                    'code' => 'item_unmapped',
                    'detail' => $item === null
                        ? "line #{$line->id} names no item."
                        : "item #{$item->id} '{$item->name}' is not a Tally stock item (no Tally identity recorded).",
                ];
            }
        }

        $godown = $this->godowns->soleTallyGodownName();
        if ($godown === null) {
            $reasons[] = [
                'code' => 'godown_unresolved',
                'detail' => 'no single Tally-linked godown to allocate the order to (a purchase order names no receiving store until its GRN).',
            ];
        }

        if ($reasons !== []) {
            throw PurchaseOrderNotPostable::because($reasons);
        }

        $expected = $order->expected_date?->toDateString();

        $lines = $order->lines->map(function ($line) use ($expected) {
            $quantity = (string) $line->quantity;
            $rate = (string) $line->unit_price;
            $amount = bcmul($quantity, $rate, 4);

            $schedules = $line->schedules
                ->filter(fn ($schedule) => $schedule->due_date !== null)
                ->values()
                ->map(fn ($schedule) => [
                    'due_date' => $schedule->due_date->toDateString(),
                    'quantity' => (string) $schedule->quantity,
                ])
                ->all();

            if ($schedules === [] && $expected !== null) {
                $schedules = [['due_date' => $expected, 'quantity' => $quantity]];
            }

            // Allocation amounts: each schedule at ITS OWN quantity × rate.
            // Whatever the schedules did not promise — an UNDER-SCHEDULED
            // line — is one REMAINDER allocation (quantity = line − Σ) dated
            // the order's expected_date when there is one, else undated
            // (the agent then emits no ORDERDUEDATE — the same rule the
            // unscheduled line already follows; a date is never made up).
            // The LAST allocation — the remainder, or the last schedule when
            // the schedules cover the line exactly — takes the amount
            // remainder, so allocation quantities AND amounts sum to the
            // line to the paisa in every case.
            $scheduledQuantity = array_reduce($schedules, fn ($carry, $schedule) => bcadd($carry, $schedule['quantity'], 4), '0.0000');
            $unscheduled = bcsub($quantity, $scheduledQuantity, 4);
            if ($schedules !== [] && bccomp($unscheduled, '0.0000', 4) === 1) {
                $schedules[] = ['due_date' => $expected, 'quantity' => $unscheduled];
            }

            $allocated = '0.0000';
            $count = count($schedules);
            foreach ($schedules as $index => $schedule) {
                $share = $index === $count - 1
                    ? bcsub($amount, $allocated, 4)
                    : bcmul($schedule['quantity'], $rate, 4);
                $schedules[$index]['amount'] = $share;
                $allocated = bcadd($allocated, $share, 4);
            }

            return [
                // The exact Tally stock-item name (a Tally-sourced item's name
                // IS the Tally name) — what <STOCKITEMNAME> must match.
                'item' => $line->item->name,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $amount,
                'schedules' => $schedules,
            ];
        })->all();

        $totalAmount = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 4), '0.0000');
        $reference = "PO-{$order->id}";

        return $this->enqueue($order, 'Purchase Order', [
            'voucher_type' => 'Purchase Order',
            'voucher_date' => $order->order_date?->toDateString(),
            'voucher_number' => $reference,
            'voucher_number_source' => 'erp',
            'party_ledger' => $partyLedger,
            'party_gstin' => $order->vendor?->gstin,
            'purchase_ledger' => $purchaseLedger,
            'godown' => $godown,
            'reference' => $reference,
            'narration' => $order->notes,
            'lines' => $lines,
            'total_amount' => $totalAmount,
        ]);
    }

    /**
     * Finished goods dispatched to a customer → Tally Delivery Note (reduces FG
     * stock). No pricing — a Delivery Note is a stock movement, not a bill.
     */
    public function enqueueDelivery(Delivery $delivery): TallySyncEntry
    {
        $delivery->loadMissing(['lines.item', 'warehouse', 'salesOrder.customer']);

        $lines = $delivery->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
        ])->all();

        return $this->enqueue($delivery, 'Delivery Note', [
            'voucher_type' => 'Delivery Note',
            'voucher_date' => $delivery->delivered_date?->toDateString(),
            'voucher_number' => "DN-{$delivery->id}",
            'party_ledger' => $delivery->salesOrder?->customer?->name,
            'party_gstin' => $delivery->salesOrder?->customer?->gstin,
            'godown' => $this->godowns->resolveName($delivery->warehouse),
            'narration' => $delivery->notes,
            'lines' => $lines,
        ]);
    }

    /**
     * An approved shift's production → Tally Manufacturing/Stock Journal:
     * consumes the raw materials issued that shift and produces the finished
     * item into the warehouse godown, carrying the batch number. The agent
     * decides the exact voucher shape (Manufacturing Journal if Tally BOM is
     * enabled, else a plain Stock Journal) — docs/archive/TALLY-SYNC-MASTER-PLAN.md §6.
     */
    public function enqueueShiftProductionEntry(ShiftProductionEntry $entry): ?TallySyncEntry
    {
        // A LOCAL- fixture product exists in this database and nowhere in
        // Tally. A voucher naming it cannot be accepted — Tally answers
        // "Stock Item does not exist" — so queueing one buys nothing and
        // costs the accountant a permanently failing row they must decide
        // what to do with. The batch itself is real and stays fully
        // recorded; only the posting is declined, and loudly, in the log.
        if ($this->isLocalFixtureEntry($entry)) {
            // Names BOTH items: the decision is taken on the resolved
            // identity (effective_item_sku), and the base product is kept
            // beside it so a reader can tell "the product is a fixture" from
            // "the product is real but its packaging identity is one".
            logger()->info('Tally voucher skipped: local-only fixture product.', [
                'shift_production_entry_id' => $entry->id,
                'item_sku' => $entry->item?->sku,
                'finished_item_id' => $entry->finished_item_id,
                'effective_item_sku' => $entry->effectiveItem()?->sku,
            ]);

            return null;
        }

        if (config('tally-sync.voucher_granularity') === 'shift') {
            return $this->enqueueShiftVoucher($entry);
        }

        return $this->enqueue($entry, 'Manufacturing Journal', $this->buildBatchVoucherPayload($entry));
    }

    /**
     * Whether this entry's produced item is a local-only fixture. Used as
     * the top-level guard, inside the shift-granularity sweep, and by both
     * payload rebuilds, so a real item's approval can never drag a
     * fixture's quantities into a shared voucher through the back door —
     * and a fixture already stamped onto one cannot keep riding it.
     *
     * A thin delegate: the predicate itself lives ONCE, on the entry
     * (ShiftProductionEntry::isLocalFixtureIdentity), where the approval
     * gate that exempts fixtures reads the same answer.
     */
    private function isLocalFixtureEntry(ShiftProductionEntry $entry): bool
    {
        return $entry->isLocalFixtureIdentity();
    }

    /**
     * The members whose lines a shift voucher CARRIES: everything stamped
     * onto it (tally_sync_entry_id) minus any local-only fixture, judged by
     * the same predicate that decides what may join.
     *
     * The predicate used to govern only the join. A fixture that had already
     * been stamped onto a voucher — under the older sweep that tested only
     * the base item's SKU prefix — was replayed unfiltered by every later
     * merge and every retry, so the voucher kept failing on a name Tally
     * does not have, for as long as anyone kept pressing Retry. Membership
     * itself is left exactly as stamped (un-stamping would be rewriting the
     * authoritative tracker from a read path); only the payload lines
     * change, and the exclusion is logged so the row is explicable.
     *
     * @param  Collection<int, ShiftProductionEntry>  $members
     * @return Collection<int, ShiftProductionEntry>
     */
    private function postableMembers(Collection $members, TallySyncEntry $voucher, string $rebuild): Collection
    {
        [$fixtures, $postable] = $members->partition(
            fn (ShiftProductionEntry $member) => $this->isLocalFixtureEntry($member),
        );

        if ($fixtures->isNotEmpty()) {
            logger()->info('Shift voucher rebuilt without local-only fixture members.', [
                'tally_sync_entry_id' => $voucher->id,
                'rebuild' => $rebuild,
                'excluded_entry_ids' => $fixtures->pluck('id')->all(),
                'reason' => 'each excluded member resolves to a local-only fixture item that exists in no Tally company; its lines are left off the payload, its membership stamp is left as it was',
            ]);

            // The same fact on the durable record — ONLY when something was
            // left off. A rebuild that excluded nothing is the ordinary
            // merge/retry, and those record themselves.
            $this->events->record(TallySyncEventKind::VoucherRebuilt, $voucher, [
                'rebuild' => $rebuild,
                'excluded_entry_ids' => $fixtures->pluck('id')->all(),
            ]);
        }

        return $postable->values();
    }

    /**
     * The per-batch voucher payload, built WITHOUT writing anything — ONE
     * manufacturing/stock journal for the batch, in the shape the owner
     * ruled on 30-Jul:
     *
     *   "one inward finished-product line using the QC-approved quantity, and
     *    outward lines for resin, masterbatch and every packing material
     *    actually used, including cartons, trays, film pouches and tape where
     *    applicable. Raw materials from the agreed RM or machine-WIP location,
     *    packing materials from the Packing Material Store, finished goods into
     *    the FG Store."
     *
     * INWARD IS ALREADY NET OF QC REJECTS, and deliberately reads the entry's
     * own `quantity_produced` rather than recomputing it: recordQualityCheck()
     * nets that column at the gate (gross − rejected) and preserves what it
     * netted in `gross_quantity_produced`, so this figure IS the QC-approved
     * count on a checked batch and the packed count on a factory that has the
     * quality stage switched off. Subtracting the rejects again here would take
     * them off the voucher twice — the owner's own warning: "QC-rejected pieces
     * are already part of the packed quantity."
     *
     * CORRECTIONS REPLACE, they never accumulate. Every figure below is read
     * from the entry's LIVE rows, and amendCompletion() deletes the previous
     * completion's consumption lines before re-booking the corrected ones (the
     * reversal pairs live only in the append-only stock ledger). So a batch
     * amended three times builds a voucher from the third calculation, not from
     * the sum of three.
     *
     * NO SCRAP OUTPUT LINE. Rejected pieces and lumps stay recorded in the
     * ERP's own stock and ride this payload as data and narration, but nothing
     * on `produced`/`consumed` books them into Tally: the owner has not yet
     * ruled whether they are kept as stock or discarded, and a scrap line
     * posted on the wrong assumption is a real quantity in someone's books.
     * The withholding is stated out loud on `withheld` rather than left as an
     * absence nobody notices.
     *
     * Split out of enqueueShiftProductionEntry() so the pre-post preview and
     * the real post are produced by the same code — a preview built by a
     * parallel implementation would eventually drift from what actually gets
     * sent, which is precisely the confidence the preview exists to provide.
     *
     * @return array<string, mixed>
     */
    public function buildBatchVoucherPayload(ShiftProductionEntry $entry): array
    {
        $entry->loadMissing(['item', 'finishedItem', 'warehouse', 'materialConsumptions.item', 'materialConsumptions.warehouse', 'scraps.scrapReason']);

        // Where packing materials post out of — one server-side answer for the
        // whole voucher (PackingVoucherLines), null when this factory has not
        // named a packing store yet. Null does NOT silently redirect the line:
        // it keeps the godown it was issued from and the preview refuses to
        // post the voucher until the store is named.
        $packingStore = $this->packing->godownName();

        // Each consumption line names its own godown (the RM store it was
        // issued from) — without it the agent falls back to the voucher's
        // FG godown and Tally deducts resin from the wrong store. Resolved
        // through TallyGodownResolver: a line issued from the internal
        // factory day bin posts under the godown Tally actually knows (the
        // bin's parent / the sole company godown), because the bin is an
        // ERP-only location the accountant's books must never see.
        //
        // A line the factory's packing-material master names — a carton, a
        // tray, film, tape — posts out of the PACKING MATERIAL STORE instead,
        // which is the owner's own split ("raw materials from the agreed RM or
        // machine-WIP location, packing materials from the Packing Material
        // Store"). The line SHAPE is untouched: same three keys, same one line
        // per consumption; only the godown a packing line names changes.
        //
        // WHAT IS NOT DONE HERE: no submitted line is ever dropped, rewritten
        // or recalculated. A supervisor who issued three rolls of tape issued
        // three rolls of tape, and PackingMaterialMappingTest holds this
        // module to that — the metres-vs-Nos question below is about the
        // CALCULATED tape figure, never about a counted issue.
        $consumed = [];
        $withheld = [];

        foreach ($entry->materialConsumptions as $consumption) {
            $kind = $this->packing->kindFor((int) $consumption->item_id);

            $consumed[] = [
                'item' => $consumption->item->name,
                'quantity' => $consumption->quantity_issued_kg,
                'godown' => ($kind === null ? null : $packingStore)
                    ?? $this->godowns->resolveName($consumption->warehouse),
            ];
        }

        // The tape the run used, calculated from the exact metres-per-carton
        // standard and withheld from posting — the owner asked for both.
        $tape = $this->packing->calculatedTape($entry, $entry->materialConsumptions->pluck('item_id')->map(fn ($id) => (int) $id)->all());

        if ($tape !== null) {
            $withheld[] = $tape;
        }

        // SHAPE FROZEN — ['item', 'quantity'] and nothing else. The produced
        // line carries no per-line godown on a Manufacturing Journal; the
        // voucher-level 'godown' below is what the agent reads for it, and
        // FactoryWarehouseResolutionTest asserts these exact keys so that a
        // later "let's also name the godown per produced line" cannot slip in.
        $produced = [[
            // The RESOLVED identity (DEC-20260810-003): the packaging's own
            // Tally item when the run's packaging carries one, frozen on the
            // entry at completion; the product's item otherwise — which is
            // every batch that predates the feature, unchanged.
            'item' => $entry->effectiveItem()->name,
            'quantity' => $entry->quantity_produced,
        ]];

        // SCRAP IS A SECOND PRODUCED LINE, because the factory's own books say
        // so. Reading 38 real Stock Journals out of their Tally settled a
        // question this code had been holding open: "Pet Scrap" arrives as an
        // INWARD line in 31 of them, in Kgs, priced between ₹17 and ₹32 — scrap
        // kept as valued stock, not thrown away. The owner confirmed it
        // (05-Aug: "yes book scrap"), and a voucher missing a line their
        // accountant has posted daily for months is a voucher they have to
        // correct by hand every time.
        //
        // The QUANTITY is the mass that did not become sellable product:
        // confirmed rejection + lumps, the same two figures the material
        // reconciliation subtracts. That is what makes the arithmetic close —
        // issued = good + rejection + lumps + unaccounted — so the scrap line
        // and the reconciliation can never disagree about the same shift.
        //
        // Still driven by the configured scrap item and still silent without
        // one: the name has to be the exact Tally stock item, and guessing
        // between "Pet Scrap", "PET Scrap - Amber" and "PET Scrap - Lumps"
        // (all three exist in their masters) would book real weight against the
        // wrong master and surface as a Tally rejection days later.
        $scrapLine = $this->producedScrapLine($entry);

        if ($scrapLine !== null) {
            $produced[] = $scrapLine;
        }

        // Scraps ride along as DATA AND NARRATION ONLY — never as a stock line.
        // Crediting rejected pieces or regrind back into Tally as a valued item
        // needs an answer this factory has not given: the owner's ruling is
        // "do not create a Tally scrap-output line until the owner confirms
        // whether rejected pieces and lumps are physically kept as stock or
        // discarded". Until then the figures reach the accountant in
        // human-readable form and as an explicit withheld line, and the ERP's
        // own scrap stock recording (QC's issue out of FG, the scrap receipt)
        // is untouched — only the Tally side is held back.
        $scraps = $entry->scraps->map(fn ($scrap) => [
            'type' => $scrap->type->value,
            'quantity_nos' => $scrap->quantity_nos,
            'quantity_kg' => $scrap->quantity_kg,
            'reason' => $scrap->scrapReason?->name,
        ])->all();

        $scrapNote = $entry->scraps
            ->map(function ($scrap) {
                $amounts = implode(' / ', array_filter([
                    $scrap->quantity_nos !== null ? "{$scrap->quantity_nos} nos" : null,
                    $scrap->quantity_kg !== null ? "{$scrap->quantity_kg} kg" : null,
                ]));

                return "{$scrap->type->value}: ".($amounts !== '' ? $amounts : '0')
                    .($scrap->scrapReason ? " ({$scrap->scrapReason->name})" : '');
            })
            ->implode('; ');

        $scrapWithheld = $this->withheldScrapLine($entry);

        if ($scrapWithheld !== null) {
            $withheld[] = $scrapWithheld;
        }

        return [
            'voucher_type' => 'Manufacturing Journal',
            'voucher_date' => $entry->production_date?->toDateString(),
            'voucher_number' => "SPE-{$entry->id}",
            'batch_number' => $entry->batch_number,
            // WHERE THE FINISHED GOODS LAND — the entry's own warehouse, which
            // is the finished-goods role FactoryWarehouseResolver named when
            // the batch STARTED and the warehouse its completion receipt was
            // actually booked into. Deliberately not the resolver's answer
            // today: a settings change after the shift must not make the
            // voucher describe a godown the stock never went to.
            'godown' => $this->godowns->resolveName($entry->warehouse),
            'narration' => trim(implode('. ', array_filter([$entry->notes, $scrapNote !== '' ? "Scrap — {$scrapNote}" : null]))),
            'produced' => $produced,
            'consumed' => $consumed,
            'scraps' => $scraps,
            // The packing-material store every packing line above posts out
            // of, named once. Null means this factory has not got one yet —
            // the preview turns that into a refusal, never into a guess.
            'packing_store' => $packingStore,
            // WHAT THIS VOUCHER DELIBERATELY DOES NOT POST, and why, in the
            // factory's own words. An absence explains nothing; this list is
            // how the accountant learns that the tape and the scrap are known,
            // counted, and waiting on an answer rather than lost.
            'withheld' => $withheld,
        ];
    }

    /**
     * The scrap this batch made, stated as a withheld line — never as stock.
     *
     * THE OWNER HAS NOW ANSWERED (05-Aug): "rejects and lumps are discarded."
     * That settles the question this line used to say was open, and it settles
     * it in favour of what the code already did — nothing is posted to Tally,
     * because discarded material is not stock anybody owns. The figures are
     * still carried so the accountant can see what the shift threw away; they
     * are simply not a voucher line.
     *
     * The consumption side needs no adjustment and that is worth stating,
     * because it looks like an omission: the resin that became a reject was
     * genuinely consumed, and the resin line already includes it (produced
     * counts are net of rejects while consumption covers everything moulded).
     * Discarding the bottle does not un-consume the resin.
     *
     * Kept as a WITHHELD line rather than dropped entirely: a shift that made
     * 237 rejects should say so on its own voucher record, and an absence
     * nobody notices is how the figure stops being looked at.
     *
     * The scrap this batch made, as an INWARD stock line — the factory's own
     * practice, read out of 31 of their 38 Stock Journals and confirmed by the
     * owner (05-Aug).
     *
     * Null when no scrap item is configured, or when the shift made no scrap.
     * Both are silences with meaning and neither is a zero line: a voucher
     * carrying "Pet Scrap 0.0000" invites Tally to create a movement for
     * nothing, and a shift that genuinely scrapped nothing should say nothing.
     *
     * @return array{item: string, quantity: string}|null
     */
    private function producedScrapLine(ShiftProductionEntry $entry): ?array
    {
        $item = $this->scrapItems->resolve();

        if ($item === null) {
            return null;
        }

        // RESOLVED LAZILY, not injected. ShiftProductionEntryService reaches
        // this class through VoucherPreviewService, so a constructor dependency
        // the other way is a container cycle — it does not fail as a clear
        // error either, it recurses until PHP dies mid-test ("premature end of
        // process"), which is a far worse thing to debug than one app() call.
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);

        if ($metrics === null) {
            return null;
        }

        // The mass that did not become sellable product. Both halves come from
        // the reconciliation's own figures so the two can never disagree.
        $kg = bcadd(
            (string) ($metrics['confirmed_rejection_kg'] ?? '0'),
            (string) ($metrics['lumps_kg'] ?? '0'),
            4,
        );

        if (bccomp($kg, '0', 4) !== 1) {
            return null;
        }

        return ['item' => $item->name, 'quantity' => $kg];
    }

    /**
     * @return array{kind: string, item: ?string, quantity: string, unit: string, reason: string}|null
     */
    private function withheldScrapLine(ShiftProductionEntry $entry): ?array
    {
        // Nothing to withhold once the scrap line actually posts — the figure is
        // on the voucher, and a "held back" note beside a posted line would be
        // simply untrue.
        $missReason = $this->scrapItems->missReason();

        if ($missReason === null) {
            return null;
        }

        $kg = '0.0000';
        $scrapNos = '0';

        foreach ($entry->scraps as $scrap) {
            if ($scrap->quantity_kg !== null) {
                $kg = bcadd($kg, (string) $scrap->quantity_kg, 4);
            }

            if ($scrap->quantity_nos !== null) {
                $scrapNos = bcadd($scrapNos, (string) $scrap->quantity_nos, 0);
            }
        }

        // Pieces rejected during the RUN, counted at completion rather than on
        // a scrap row. Reported BESIDE the scrap-line pieces, never added to
        // them: the owner's own distinction is that "packed pieces sent to QC
        // and pieces rejected during production are separate", and a supervisor
        // may also have entered a rejected-finished-good scrap line of their
        // own — summing the two would quote a bigger rejection than happened.
        $runNos = $entry->quantity_scrap === null ? '0' : bcadd('0', (string) $entry->quantity_scrap, 0);

        $counted = array_values(array_filter([
            bccomp($runNos, '0', 0) === 1 ? "{$runNos} pieces rejected during the run" : null,
            bccomp($scrapNos, '0', 0) === 1 ? "{$scrapNos} pieces on its scrap lines" : null,
            bccomp($kg, '0', 4) === 1 ? "{$kg} kg of lumps and scrap" : null,
        ]));

        if ($counted === []) {
            return null;
        }

        return [
            'kind' => PackingVoucherLines::WITHHELD_SCRAP,
            'item' => null,
            // Only the kg total is a summable figure; the piece counts are two
            // different populations and are stated, not added, in the reason.
            'quantity' => $kg,
            'unit' => 'kg',
            'reason' => 'This batch recorded '.implode(', ', $counted).'. '
                .$this->scrapWithheldBecause($missReason).' '.$this->scrapItemMissNote($missReason),
        ];
    }

    /**
     * WHY the scrap line is held back — the lead sentence, branched on which
     * of the two silences this is, because they are different claims. Not
     * naming a scrap item is the factory's standing choice, so that lead
     * states the owner's ruling. Naming one that cannot be found is a
     * misconfiguration, and a lead that still cited the ruling would be
     * dressing a typo up as a decision — the very contradiction the note
     * appended after it exists to call out.
     */
    private function scrapWithheldBecause(string $missReason): string
    {
        if ($missReason === ScrapItemResolver::NOT_NAMED) {
            return 'No scrap line is posted to Tally because the factory discards rejects and lumps '
                .'(owner ruling, 05-Aug) — discarded material is not stock anyone owns. The resin they used is '
                .'already on the consumption line above; only the bottle is thrown away, not the material that '
                .'went into it.';
        }

        return 'No scrap line is posted to Tally because the scrap item named in configuration could not be '
            .'found among the stock items, so the figure is held back rather than booked against a guess.';
    }

    /**
     * Which of the two silences this is — said out loud, because they call
     * for different people. "Not named" is the factory's standing choice
     * and needs nobody; "named but not found" is a misconfiguration (a typo,
     * a renamed SKU, a retired master) that withholds every scrap line while
     * reading exactly like that choice.
     */
    private function scrapItemMissNote(string $missReason): string
    {
        if ($missReason === ScrapItemResolver::NOT_NAMED) {
            return 'Scrap item: not named in configuration (production.scrap.rejected_item_sku is blank).';
        }

        return sprintf(
            'Scrap item: the configured \'%s\' matches no stock item by SKU or exact name — '
            .'check production.scrap.rejected_item_sku; this is a misconfiguration, not a decision.',
            (string) $this->scrapItems->configuredName(),
        );
    }

    /**
     * 'shift' voucher granularity (config tally-sync.voucher_granularity):
     * instead of one voucher per approved entry, everything a shift produced
     * aggregates into ONE Stock Journal per (production_date, shift) —
     * consumption summed item+godown-wise, production totals item-wise (kept
     * per FG godown so the agent books each into the store it actually
     * landed in). Voucher number: SJ-{Ymd}-S{shift_id}, with -2/-3 suffixes
     * for follow-up vouchers.
     *
     * Membership tracking: shift_production_entries.tally_sync_entry_id — a
     * scalar FK, so an entry can belong to exactly ONE voucher ever, by
     * construction (a pivot would have to police uniqueness; a single-valued
     * column can't double-book). While the shift's voucher is still pending,
     * later approvals merge into it and the payload is rebuilt from all
     * members; once it has synced (or failed), it is closed — its numbers
     * are already in Tally's books — so later approvals open a follow-up
     * voucher instead of mutating history.
     */
    private function enqueueShiftVoucher(ShiftProductionEntry $entry): TallySyncEntry
    {
        return DB::transaction(function () use ($entry) {
            // Idempotent: re-announcing an already-vouchered entry returns
            // its voucher untouched instead of double-counting quantities.
            if ($entry->tally_sync_entry_id !== null) {
                return TallySyncEntry::query()->findOrFail($entry->tally_sync_entry_id);
            }

            $date = $entry->production_date->toDateString();

            // Approved shift-mates not yet in any voucher — normally just
            // the entry that triggered this call; the lock closes the race
            // of two approvals landing at once claiming the same rows.
            $joining = ShiftProductionEntry::query()
                ->whereDate('production_date', $date)
                ->where('shift_id', $entry->shift_id)
                ->where('status', ShiftProductionEntryStatus::Approved->value)
                ->whereNull('tally_sync_entry_id')
                // Entries approved under batch mode already own a per-entry
                // voucher — sweeping them here after a granularity flip
                // would book every quantity into Tally twice.
                ->whereDoesntHave('tallySyncEntries', fn ($query) => $query->whereIn(
                    'status',
                    [TallySyncStatus::Pending->value, TallySyncStatus::Synced->value],
                ))
                ->with(['item', 'finishedItem'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                // Local-only fixtures never join a voucher. Without this the
                // top-level guard would be bypassed sideways: a REAL item's
                // approval sweeps its approved shift-mates, and a fixture
                // sitting in the same shift would have its quantities posted
                // to Tally under someone else's approval.
                //
                // Judged in PHP by THE SAME predicate as the top-level guard,
                // not by a second one written in SQL. The SQL form this
                // replaced tested only the base item's SKU prefix, and so
                // disagreed with isLocalFixtureEntry() twice over: a real
                // product whose packaging identity (finished_item_id) is a
                // fixture slipped in, and so would a column-flagged fixture
                // whose SKU no longer starts LOCAL-. One predicate cannot
                // drift from itself.
                //
                // Item soft-deletes, and this keeps the guarantee the SQL form
                // had: an entry whose product was retired after the run has
                // NO visible item row, effectiveItem() is null, and the
                // predicate says "not a fixture" — its quantities stay on the
                // voucher rather than silently dropping off it.
                ->reject(function (ShiftProductionEntry $member) use ($entry): bool {
                    if (! $this->isLocalFixtureEntry($member)) {
                        return false;
                    }

                    // Not silent: the row stays approved and unvouchered,
                    // and the log is where a reader learns why the sweep
                    // left it there.
                    logger()->info('Shift sweep left a local-only fixture entry unvouchered.', [
                        'shift_production_entry_id' => $member->id,
                        'triggered_by_entry_id' => $entry->id,
                        'item_sku' => $member->item?->sku,
                        'finished_item_id' => $member->finished_item_id,
                        'effective_item_sku' => $member->effectiveItem()?->sku,
                    ]);

                    return true;
                })
                ->values();

            if ($joining->isEmpty()) {
                // A concurrent approval already claimed this entry.
                return TallySyncEntry::query()->findOrFail($entry->fresh()->tally_sync_entry_id);
            }

            // Vouchers this (date, shift) already has — derived from the
            // membership column, never by parsing voucher numbers.
            $voucherIds = ShiftProductionEntry::query()
                ->whereDate('production_date', $date)
                ->where('shift_id', $entry->shift_id)
                ->whereNotNull('tally_sync_entry_id')
                ->distinct()
                ->pluck('tally_sync_entry_id');

            // Merge-open means Pending AND never handed to the agent: a
            // payload the agent may already hold must not change under it
            // (it would ack the old shape and silently drop the merged
            // entries from Tally). Row-locked against a concurrent ack.
            $voucher = TallySyncEntry::query()
                ->whereIn('id', $voucherIds)
                ->where('status', TallySyncStatus::Pending)
                ->whereNull('delivered_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($voucher === null) {
                $sequence = $voucherIds->count() + 1;
                $number = "SJ-{$entry->production_date->format('Ymd')}-S{$entry->shift_id}"
                    .($sequence > 1 ? "-{$sequence}" : '');

                $voucher = TallySyncEntry::create([
                    // The morph names the Shift: a shift voucher belongs to
                    // no single entry. Members hang off tally_sync_entry_id.
                    'syncable_type' => (new Shift)->getMorphClass(),
                    'syncable_id' => $entry->shift_id,
                    // The voucher's own honest label (DEC-20260807-010). The
                    // agent dispatches builders on this STRING: agents >= 0.3.5
                    // carry a 'Stock Journal' builder; older agents fail it
                    // with "No XML builder" (entries #33/#34, 07-Aug), which is
                    // why this label flips ONLY after the factory agent is
                    // confirmed >= 0.3.5 — merging early recreates that outage.
                    'tally_voucher_type' => 'Stock Journal',
                    'payload' => $this->shiftVoucherPayload($joining, $number, $entry),
                    'status' => TallySyncStatus::Pending,
                    'attempts' => 0,
                    // Creation is the first merge — it starts the release
                    // gate's idle-hold clock (ShiftVoucherReleaseGate).
                    'last_merged_at' => now(),
                ]);

                ShiftProductionEntry::query()
                    ->whereIn('id', $joining->pluck('id'))
                    ->update(['tally_sync_entry_id' => $voucher->id]);

                // Recorded once the voucher AND its membership stamps are
                // in — inside this transaction, so a rolled-back creation
                // leaves no ghost in the history.
                $details = [
                    'voucher_type' => $voucher->tally_voucher_type,
                    'voucher_number' => $number,
                    'entry_ids' => $joining->pluck('id')->all(),
                ];

                // A -2/-3 names the voucher it follows on: the latest earlier
                // voucher of this (date, shift), closed (synced / failed /
                // delivered) — which is the whole reason a new one exists.
                if ($sequence > 1) {
                    $details['follow_up_of'] = $voucherIds->max();
                }

                $this->events->record(TallySyncEventKind::VoucherEnqueued, $voucher, $details);

                return $voucher;
            }

            ShiftProductionEntry::query()
                ->whereIn('id', $joining->pluck('id'))
                ->update(['tally_sync_entry_id' => $voucher->id]);

            // Rebuild the payload from ALL members (pre-existing + just
            // joined) — recomputing the sums beats patching incrementally.
            // Filtered through the same fixture predicate that governed the
            // join above: a fixture stamped on under the old sweep must not
            // be replayed onto the payload on every later merge.
            $members = $this->postableMembers(
                ShiftProductionEntry::query()
                    ->where('tally_sync_entry_id', $voucher->id)
                    ->orderBy('id')
                    ->get(),
                $voucher,
                'merge',
            );
            $voucher->update([
                'payload' => $this->shiftVoucherPayload($members, $voucher->payload['voucher_number'], $entry),
                // Every merge re-arms the release gate's quiet period: the
                // voucher leaves only after it has sat untouched for the
                // configured idle-hold (DEC-20260807-011).
                'last_merged_at' => now(),
            ]);

            // WHICH entries joined WHEN — the one thing last_merged_at
            // (overwritten on every merge) can never say afterwards.
            $this->events->record(TallySyncEventKind::VoucherMerged, $voucher, [
                'joined_entry_ids' => $joining->pluck('id')->all(),
                'voucher_number' => $voucher->payload['voucher_number'],
            ]);

            return $voucher->fresh();
        });
    }

    /**
     * Aggregate a shift voucher's members into one Stock Journal payload:
     * consumption lines summed per (item, godown) so the agent deducts each
     * RM from the store it was actually issued from, production totals per
     * (item, FG godown).
     *
     * @param  Collection<int, ShiftProductionEntry>  $members
     */
    private function shiftVoucherPayload(Collection $members, string $voucherNumber, ShiftProductionEntry $entry): array
    {
        $members->loadMissing(['item', 'warehouse', 'shift', 'materialConsumptions.item', 'materialConsumptions.warehouse']);

        // Godown names resolve through TallyGodownResolver, same as the
        // per-batch payload: a line issued from the internal day bin books
        // under its parent godown's name (the accountant's single godown),
        // never under a warehouse Tally has never heard of. Aggregation
        // still keys on the REAL warehouse_id — two internal bins aliasing
        // to the same godown stay separate lines, which Tally sums anyway.
        // The SAME packing split the per-batch payload applies — cartons,
        // trays, film and tape post out of the Packing Material Store, resin
        // and masterbatch out of the location they were issued from (the
        // owner, 30-Jul: "Raw materials from the agreed RM or machine-WIP
        // location, packing materials from the Packing Material Store").
        //
        // It has to be applied HERE TOO and not only in buildBatchVoucherPayload:
        // this is a second payload builder feeding the same Tally, chosen by
        // config (tally-sync.voucher_granularity = 'shift'), and a rule that
        // holds on one of two posting paths is not a rule. Null store means
        // the line keeps the godown it was issued from — never a silent
        // redirect — exactly as the batch path leaves it.
        $packingStore = $this->packing->godownName();

        $consumed = [];
        $produced = [];
        foreach ($members as $member) {
            foreach ($member->materialConsumptions as $consumption) {
                $key = "{$consumption->item_id}@{$consumption->warehouse_id}";
                $kind = $this->packing->kindFor((int) $consumption->item_id);
                $consumed[$key] = [
                    'item' => $consumption->item->name,
                    'quantity' => bcadd($consumed[$key]['quantity'] ?? '0.0000', (string) $consumption->quantity_issued_kg, 4),
                    'godown' => ($kind === null ? null : $packingStore)
                        ?? $this->godowns->resolveName($consumption->warehouse),
                ];
            }

            // Aggregated per RESOLVED identity (DEC-20260810-003), not per
            // product: two packings of one product whose packagings carry
            // different Tally items land as their two Tally lines, and two
            // batches resolving to the same identity still merge into one.
            $finishedId = $member->effectiveItemId();
            $key = "{$finishedId}@{$member->warehouse_id}";
            $produced[$key] = [
                'item' => $member->effectiveItem()->name,
                'quantity' => bcadd($produced[$key]['quantity'] ?? '0.0000', (string) $member->quantity_produced, 4),
                'godown' => $this->godowns->resolveName($member->warehouse),
            ];
        }

        $batches = $members->pluck('batch_number')->filter()->values();

        return [
            // The honest label (see the enqueue-side comment on agent
            // sequencing). batch_number/godown stay: the 0.3.5 Stock Journal
            // builder uses the voucher-level godown as the fallback for any
            // line without its own, and keeping the contract identical means
            // a voucher regenerated across the flip changes only this label.
            'voucher_type' => 'Stock Journal',
            'voucher_date' => $entry->production_date->toDateString(),
            'voucher_number' => $voucherNumber,
            'batch_number' => null,
            'godown' => $this->godowns->resolveName($members->first()?->warehouse),
            'shift' => $members->first()?->shift?->name,
            'narration' => trim("Shift production — {$members->count()} entries"
                .($batches->isNotEmpty() ? '. Batches: '.$batches->implode(', ') : '')),
            'produced' => array_values($produced),
            'consumed' => array_values($consumed),
            // Human/agent-readable membership; the authoritative tracker is
            // shift_production_entries.tally_sync_entry_id.
            'entry_ids' => $members->pluck('id')->all(),
        ];
    }

    /**
     * @param  Authenticatable|null  $actor  the polling agent (its token names the installation) — recorded on each delivery
     */
    public function pending(?Authenticatable $actor = null): Collection
    {
        // ONE transaction around read → stamp → record. Before this the
        // stamp and the events were separate statements: an insert error
        // 500'd the poll AFTER delivered_at had landed, the response never
        // reached the agent, and on the next poll every one of those
        // vouchers arrived already stamped — which the agent's guard reads
        // as "handed to an agent it has no record of" (postDecision.ts) and
        // refuses until a human retries each one. Two polls racing could
        // also both record pending.delivered from their own pre-stamp reads.
        // Inside one transaction the stamp rolls back with the failed
        // insert, so the next poll delivers cleanly, and the recorded set is
        // the ids THIS call's stamp actually landed on — selected FOR UPDATE
        // so a concurrent poll waits and then finds them stamped.
        //
        // What is returned, and in what order, is unchanged: the same
        // pending rows the gate does not withhold, id ascending, each
        // carrying its delivered_at AS READ — see below.
        return DB::transaction(function () use ($actor) {
            $entries = TallySyncEntry::query()
                ->where('status', TallySyncStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                // The release gate fronts the hand-out: a shift voucher whose
                // shift is still collecting (or that merged less than the
                // idle-hold ago) is not offered, so its merge window below
                // stays open (DEC-20260807-011). Batch vouchers and anything
                // already delivered pass untouched — see ShiftVoucherReleaseGate.
                ->reject(fn (TallySyncEntry $entry) => $this->releaseGate->withholds($entry))
                ->values();

            // First delivery closes the merge window (see the shift-voucher
            // merge query). Unacked vouchers keep reappearing on later polls.
            //
            // The rows are READ BEFORE THEY ARE STAMPED on purpose, and the
            // agent depends on it: the delivered_at each entry carries in this
            // response is its value as of the moment of this poll — null the
            // first time it is handed out, set on every re-poll. That is the
            // agent's idempotency line ("have I been given this voucher
            // before?"), the one thing standing between a lost acknowledgement
            // and the same voucher posted twice into live books. Stamp first
            // and every delivery looks like a re-delivery; drop the mass
            // update's ->whereNull() and a re-delivery looks brand new. Keep
            // this ordering. Covered by VoucherPostedOnceTest.
            //
            // The ids to stamp are the unstamped ones among the rows just
            // locked and read — this call's own set, not a second read.
            $newlyDelivered = $entries
                ->filter(fn (TallySyncEntry $entry) => $entry->delivered_at === null)
                ->values();

            if ($newlyDelivered->isNotEmpty()) {
                TallySyncEntry::query()
                    ->whereIn('id', $newlyDelivered->pluck('id'))
                    ->whereNull('delivered_at')
                    ->update(['delivered_at' => now()]);
            }

            // One 'pending.delivered' PER ENTRY the stamp above just landed on.
            // A re-poll of an unacked voucher is NOT recorded again: the
            // agent's own guard refuses to act on a voucher that arrives
            // already stamped, so a repeat hand-out is a heartbeat, not a
            // delivery, and stamping it every ~90 s would bury the history
            // under noise. The file log keeps its per-poll line (agentLog)
            // untouched.
            foreach ($newlyDelivered as $entry) {
                $this->events->record(TallySyncEventKind::PendingDelivered, $entry, [
                    'voucher_type' => $entry->tally_voucher_type,
                    'voucher_number' => $entry->payload['voucher_number'] ?? null,
                ], actor: $actor);
            }

            return $entries;
        });
    }

    /**
     * @param  Authenticatable|null  $actor  the reporting agent — recorded on the history row
     */
    public function markSynced(TallySyncEntry $entry, ?Authenticatable $actor = null): TallySyncEntry
    {
        $entry->update([
            'status' => TallySyncStatus::Synced,
            'synced_at' => now(),
            'error_message' => null,
        ]);

        $this->events->record(TallySyncEventKind::VoucherSynced, $entry, [
            'voucher_type' => $entry->tally_voucher_type,
            'voucher_number' => $entry->payload['voucher_number'] ?? null,
        ], actor: $actor);

        return $entry;
    }

    /**
     * The agent reporting that Tally rejected this voucher.
     *
     * Refused, loudly but harmlessly, once the voucher is already synced:
     * "failed" is what puts the Retry button on the dashboard, and retrying
     * a voucher Tally has already accepted posts it into the live books a
     * second time. The only way a synced entry can be reported failed is a
     * confused/duplicated report — a stale agent replaying an old queue, or
     * an ack that succeeded on a connection whose response never arrived —
     * and in every one of those cases the books already hold the voucher.
     *
     * Note the guard is on SYNCED, not on delivered: every entry the agent
     * can report on has been delivered to it by definition (pending()
     * stamps delivered_at on the way out), so refusing delivered entries
     * would silence real Tally rejections ("Stock Item does not exist")
     * entirely — the one thing SyncFailureVisibilityTest exists to protect.
     */
    public function markFailed(TallySyncEntry $entry, string $errorMessage, ?Authenticatable $actor = null): TallySyncEntry
    {
        if ($entry->isInTally()) {
            logger()->warning('Tally sync failure ignored: that voucher is already in Tally.', [
                'tally_sync_entry_id' => $entry->id,
                'voucher_number' => $entry->voucherNumber(),
                'synced_at' => $entry->synced_at?->toIso8601String(),
                'reported_error' => $errorMessage,
            ]);

            // The refusal is itself history: the agent DID report a failure
            // for a voucher in the books, and a reader of this entry's
            // timeline should see that the report arrived and was declined —
            // not nothing.
            $this->events->record(TallySyncEventKind::VoucherFailureRefused, $entry, [
                'error_message' => $errorMessage,
                'synced_at' => $entry->synced_at?->toIso8601String(),
            ], actor: $actor);

            return $entry;
        }

        $entry->update([
            'status' => TallySyncStatus::Failed,
            'error_message' => $errorMessage,
            'attempts' => $entry->attempts + 1,
        ]);

        // Each failure is its own row with its own error and attempt number:
        // the entry's error_message is overwritten by the next failure, this
        // never is.
        $this->events->record(TallySyncEventKind::VoucherFailed, $entry, [
            'error_message' => $errorMessage,
            'attempt' => $entry->attempts,
        ], actor: $actor);

        return $entry;
    }

    /**
     * Put a voucher back in the queue for the agent to post again.
     *
     * Refuses outright once the voucher is in Tally — see markFailed(). By
     * the time anyone can click Retry the money is in someone's books, so
     * the honest answer is "go look at Tally", not a second voucher.
     *
     * Clearing delivered_at is what actually re-arms the agent: the agent
     * refuses to rebuild a voucher for any entry that arrives already
     * stamped as delivered (it cannot tell a lost ack from a lost post),
     * so a re-queue that left the stamp in place would be a Retry button
     * that quietly does nothing. Nulling it makes each retry a ONE-SHOT
     * posting authorisation — pending() re-stamps on the very next poll, so
     * the guard closes again by itself.
     *
     * @param  Authenticatable|null  $actor  the person pressing Retry — recorded on the history row
     *
     * @throws ValidationException 422 naming the voucher to check in Tally
     */
    public function retry(TallySyncEntry $entry, ?int $userId = null, ?Authenticatable $actor = null): TallySyncEntry
    {
        if ($entry->isInTally()) {
            $synced = $entry->synced_at?->format('d M Y H:i');

            throw ValidationException::withMessages([
                'entry' => "This voucher is already in Tally as {$entry->voucherNumber()}"
                    .($synced !== null ? " (synced {$synced})" : '')
                    .' — check Tally before anything else. Re-queueing it would post the same voucher twice.',
            ]);
        }

        // A dismissed voucher is dead by a human's explicit say-so — "will
        // never be sent to Tally" is the promise the Dismiss confirmation
        // made, and a Retry that could quietly resurrect it would turn that
        // promise into a race between two buttons. It is not failed any
        // more, so nothing on the dashboard offers Retry for it; this guard
        // is for stale pages and other API clients.
        if ($entry->status === TallySyncStatus::Dismissed) {
            throw ValidationException::withMessages([
                'entry' => "{$entry->voucherNumber()} was dismissed — it is never to be sent to Tally. "
                    .'If it genuinely should post after all, that is a new decision: raise it with the owner '
                    .'rather than re-queueing a voucher someone deliberately wrote off.',
            ]);
        }

        // REGENERATE, DON'T REPLAY. A failed voucher usually failed because
        // a mapping was wrong — the product had no Tally identity, a store
        // was unmapped — and Accounts has since FIXED the mapping. Replaying
        // the frozen payload would fail identically forever. Rebuilding it
        // from the source records picks the fix up; the old payload's error
        // and the regeneration are recorded on the entry so the story of
        // "it failed, someone fixed the mapping, it went through" is
        // readable afterwards. Idempotency is untouched: an entry that ever
        // reached Tally is refused above, and the agent-side delivered_at
        // guard stays exactly as it was.
        //
        // One transaction from regenerate to the last event: the rebuild
        // may record voucher.rebuilt (postableMembers) before the entry is
        // updated, and voucher.retried after — with no transaction a
        // failure between them left an event for a retry that never
        // landed, or a landed retry with no row saying who did it.
        return DB::transaction(function () use ($entry, $userId, $actor) {
            $failedError = $entry->error_message;
            $regenerated = $this->regeneratePayload($entry);
            $payload = $regenerated ?? $entry->payload;

            $log = $payload['resolution_log'] ?? [];
            if ($failedError !== null) {
                $log[] = [
                    'at' => now()->toIso8601String(),
                    'by' => $userId,
                    'previous_error' => $failedError,
                    'note' => 'Payload regenerated from current mappings and re-queued.',
                ];
            }
            $payload['resolution_log'] = $log;

            $entry->update([
                'status' => TallySyncStatus::Pending,
                'error_message' => null,
                'delivered_at' => null,
                'payload' => $payload,
                // The dispatch label rides with the regenerated payload: the
                // first shift vouchers were enqueued under 'Stock Journal', a
                // label the deployed agent has no builder for, and a retry that
                // kept the frozen label would fail identically forever.
                'tally_voucher_type' => is_string($payload['voucher_type'] ?? null)
                    ? $payload['voucher_type']
                    : $entry->tally_voucher_type,
            ]);

            // WHO retried, and whether the payload was rebuilt or replayed —
            // the resolution_log above lives inside the mutable payload the
            // next regenerate rewrites; this row does not.
            $this->events->record(TallySyncEventKind::VoucherRetried, $entry, [
                'payload_regenerated' => $regenerated !== null,
                'previous_error' => $failedError,
            ], actor: $actor);

            return $entry;
        });
    }

    /**
     * Write a dead voucher off: it will NEVER be sent to Tally.
     *
     * DISMISS, NOT DELETE — nothing on this queue is ever erased. The row
     * keeps its payload, its error and its attempt count, and the act itself
     * lands in the resolution_log, so "what was this and why did nobody post
     * it" stays answerable years later. What changes is the status: a
     * dismissed voucher is invisible to pending() (the agent can never
     * collect it) and refused by retry() (no button can resurrect it).
     *
     * Exists because "leave it failed forever" stopped being safe: a failed
     * demo voucher is one stray Resync away from posting into the live books
     * the moment the factory's Tally is open on the right company. Failed
     * was never a terminal state — this is.
     *
     * Only a FAILED voucher can be dismissed. A pending one is on its way to
     * the agent — the honest move there is to let the agent report first,
     * not to change a voucher's fate while it may already be posting. And a
     * voucher that ever reached Tally (synced_at set) is refused outright,
     * same as retry(): the books already hold it, and a "dismissed" label
     * over a posted voucher would be a lie the accountant acts on.
     *
     * @param  Authenticatable|null  $actor  the person writing it off — recorded on the history row
     *
     * @throws ValidationException
     */
    public function dismiss(TallySyncEntry $entry, ?int $userId = null, ?Authenticatable $actor = null): TallySyncEntry
    {
        if ($entry->isInTally()) {
            $synced = $entry->synced_at?->format('d M Y H:i');

            throw ValidationException::withMessages([
                'entry' => "This voucher is already in Tally as {$entry->voucherNumber()}"
                    .($synced !== null ? " (synced {$synced})" : '')
                    .' — check Tally before anything else. Dismissing it here would hide a voucher that is really in the books.',
            ]);
        }

        if ($entry->status !== TallySyncStatus::Failed) {
            throw ValidationException::withMessages([
                'entry' => "Only a failed voucher can be dismissed — {$entry->voucherNumber()} is {$entry->status->value}. "
                    .'A voucher the agent may still be posting cannot be written off mid-flight; wait for its result first.',
            ]);
        }

        // The act goes on the record in the same shape retry() writes, so
        // one log tells the whole story in order: failed, retried, failed
        // again, dismissed. error_message deliberately stays on the row —
        // dismissal is not a repair, and the reason it failed is part of
        // why it was written off. No reason of its own is taken today (the
        // endpoint carries no body); none is invented.
        return $this->writeOff(
            $entry,
            note: 'Dismissed — will never be sent to Tally.',
            details: ['previous_error' => $entry->error_message],
            userId: $userId,
            actor: $actor,
        );
    }

    /**
     * WITHDRAW a voucher THIS ERP staged and the agent has NOT collected —
     * the one dismissal nobody has to click, because the document behind it
     * stopped existing: a purchase order cancelled or short-closed while
     * its Purchase Order voucher was still sitting Pending in our own
     * queue (Phase 6; TallySyncEventServiceProvider's PurchaseOrderCancelled
     * / PurchaseOrderClosed listeners are the only callers).
     *
     * The SAME write-off dismiss() performs — status Dismissed, the act in
     * the resolution_log, a voucher.dismissed history row — so the queue's
     * history reads identically however a row died. What differs is who may
     * be written off and why: dismiss() writes off a FAILED voucher a human
     * gave up on; this writes off a PENDING one nothing has ever seen.
     *
     * Refused for anything the ERP no longer owns — a row already delivered
     * to the agent, already synced, already failed, or already dismissed.
     * Those are left exactly as they are: what Tally should be told about an
     * order it has already received (an Alter or a Cancel voucher) is owner
     * question Q48, and this method never guesses it.
     *
     * @param  string  $reasonCode  the withdrawal's own reason, on the history row
     * @param  string  $note  the human sentence for the resolution_log
     */
    public function withdrawUncollected(TallySyncEntry $entry, string $reasonCode, string $note, ?int $userId = null, ?Authenticatable $actor = null): ?TallySyncEntry
    {
        if ($entry->status !== TallySyncStatus::Pending || $entry->delivered_at !== null || $entry->isInTally()) {
            return null;
        }

        return $this->writeOff(
            $entry,
            note: $note,
            details: ['reason' => $reasonCode],
            userId: $userId,
            actor: $actor,
        );
    }

    /**
     * THE dismissal write, shared by dismiss() and withdrawUncollected() so
     * a withdrawn row and a written-off one are the same kind of dead: one
     * status change, one resolution_log line, one voucher.dismissed history
     * row. Judges nothing — every caller has already decided.
     *
     * @param  array<string, mixed>  $details  what the history row carries beyond the act itself
     */
    private function writeOff(TallySyncEntry $entry, string $note, array $details, ?int $userId, ?Authenticatable $actor): TallySyncEntry
    {
        $payload = $entry->payload;
        $log = $payload['resolution_log'] ?? [];
        $log[] = [
            'at' => now()->toIso8601String(),
            'by' => $userId,
            'previous_error' => $entry->error_message,
            'note' => $note,
        ];
        $payload['resolution_log'] = $log;

        $entry->update([
            'status' => TallySyncStatus::Dismissed,
            'payload' => $payload,
        ]);

        $this->events->record(TallySyncEventKind::VoucherDismissed, $entry, $details, actor: $actor);

        return $entry;
    }

    /**
     * The accountant's "Release now" — DEC-20260807-011's manual override
     * on the shift-voucher release gate. Marks the voucher deliverable
     * immediately; the agent collects it on its next poll (within ~90 s).
     * Persisted (released_at / released_by) so who released what stays
     * auditable.
     *
     * Refused for anything the gate is not actually holding: a stale page
     * asking to release a voucher that is already with the agent — or
     * already in Tally — deserves the honest answer, not a silent no-op
     * that looks like it did something.
     *
     * @param  Authenticatable|null  $actor  the accountant releasing it — recorded on the history row
     *
     * @throws ValidationException
     */
    public function releaseNow(TallySyncEntry $entry, ?int $userId = null, ?Authenticatable $actor = null): TallySyncEntry
    {
        if ($entry->isInTally()) {
            throw ValidationException::withMessages([
                'entry' => "This voucher is already in Tally as {$entry->voucherNumber()} — there is nothing to release.",
            ]);
        }

        if (! $this->releaseGate->withholds($entry)) {
            throw ValidationException::withMessages([
                'entry' => "{$entry->voucherNumber()} is not waiting for release — it is already on its way to the agent.",
            ]);
        }

        $entry->update([
            'released_at' => now(),
            'released_by' => $userId,
        ]);

        $this->events->record(TallySyncEventKind::VoucherReleased, $entry, [
            'voucher_number' => $entry->payload['voucher_number'] ?? null,
        ], actor: $actor);

        return $entry;
    }

    /**
     * Rebuild a queued voucher's payload from its source records — the
     * mapping fixes made since it failed are exactly what a retry needs to
     * pick up. Types without a rebuilder keep their frozen payload (null).
     */
    private function regeneratePayload(TallySyncEntry $entry): ?array
    {
        // A morph type that no longer resolves (renamed model, historical
        // junk) must degrade to the frozen payload, not a 500.
        if (! class_exists($entry->syncable_type)) {
            return null;
        }

        $source = $entry->syncable;
        if ($source === null) {
            return null;
        }

        $fresh = match (true) {
            $source instanceof ShiftProductionEntry => $this->buildBatchVoucherPayload($source),
            $source instanceof Shift => $this->rebuildShiftVoucherPayload($entry),
            default => null,
        };

        if ($fresh === null) {
            return null;
        }

        // Keep the history the old payload accumulated.
        $fresh['resolution_log'] = $entry->payload['resolution_log'] ?? [];

        return $fresh;
    }

    /**
     * A shift voucher rebuilt from its member entries — the rows whose
     * tally_sync_entry_id names this voucher, which is the authoritative
     * membership tracker — minus any local-only fixture among them (see
     * postableMembers): a retry exists to pick up a mapping fix, and a
     * fixture line is the one thing no mapping fix can ever make postable.
     * The voucher number is kept from the frozen payload: it is the
     * voucher's identity, not derived state.
     */
    private function rebuildShiftVoucherPayload(TallySyncEntry $voucher): ?array
    {
        $members = $this->postableMembers(
            ShiftProductionEntry::query()
                ->where('tally_sync_entry_id', $voucher->id)
                ->orderBy('id')
                ->get(),
            $voucher,
            'retry',
        );

        $first = $members->first();
        $number = $voucher->payload['voucher_number'] ?? null;

        // No postable member at all — every stamped row is a fixture, or
        // there are none. Nothing honest can be built, so the frozen payload
        // stands (and keeps failing, which is the truthful outcome for a
        // voucher a human should dismiss); the log above already names why.
        if ($first === null || ! is_string($number)) {
            return null;
        }

        return $this->shiftVoucherPayload($members, $number, $first);
    }

    /**
     * The generic queue path (Sales, Journal, Receipt Note, Delivery Note,
     * Purchase Order, batch-mode production). IDEMPOTENT per (syncable, voucher type) since
     * Phase 3.5: a live entry — pending, synced or failed — already standing
     * for this document is returned as-is, and NOTHING is recorded (no new
     * row, no voucher.enqueued event). Before this, a replayed domain event
     * (a re-fired DeliveryDispatched, a re-saved issued invoice) queued a
     * second voucher for the same document, and a Delivery in particular had
     * no replay key of its own — the "Delivery has no replay key" gap named
     * in Phase 3.
     *
     * A DISMISSED entry does not count: dismissal is the human's write-off,
     * and a fresh enqueue after it is today the only road by which such a
     * voucher can be re-issued (DismissedVoucherTest) — that road stays
     * open. Failed does count: a failed voucher is retried in place, never
     * doubled beside itself.
     *
     * The payload of a returned entry is NOT refreshed here — an entry the
     * agent may already hold must not change under it (the same reason
     * enqueueShiftVoucher() only merges into never-delivered vouchers);
     * retry() is where a payload is regenerated, deliberately.
     */
    private function enqueue(Model $syncable, string $voucherType, array $payload): TallySyncEntry
    {
        $existing = TallySyncEntry::query()
            ->where('syncable_type', $syncable->getMorphClass())
            ->where('syncable_id', $syncable->getKey())
            ->where('tally_voucher_type', $voucherType)
            ->where('status', '!=', TallySyncStatus::Dismissed->value)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $entry = TallySyncEntry::create([
            'syncable_type' => $syncable->getMorphClass(),
            'syncable_id' => $syncable->getKey(),
            'tally_voucher_type' => $voucherType,
            'payload' => $payload,
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);

        // The first row of every voucher's history. No actor: an enqueue is
        // the side effect of a domain event (an approval, an issued
        // invoice), not an act anyone performed on the queue.
        $this->events->record(TallySyncEventKind::VoucherEnqueued, $entry, [
            'voucher_type' => $voucherType,
            'voucher_number' => $payload['voucher_number'] ?? null,
        ]);

        return $entry;
    }
}
