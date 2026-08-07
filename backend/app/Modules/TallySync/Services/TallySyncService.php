<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
            logger()->info('Tally voucher skipped: local-only fixture product.', [
                'shift_production_entry_id' => $entry->id,
                'item_sku' => $entry->item?->sku,
            ]);

            return null;
        }

        if (config('tally-sync.voucher_granularity') === 'shift') {
            return $this->enqueueShiftVoucher($entry);
        }

        return $this->enqueue($entry, 'Manufacturing Journal', $this->buildBatchVoucherPayload($entry));
    }

    /**
     * Whether this entry's produced item is a local-only fixture. Used both
     * as the top-level guard and inside the shift-granularity sweep, so a
     * real item's approval can never drag a fixture's quantities into a
     * shared voucher through the back door.
     */
    private function isLocalFixtureEntry(ShiftProductionEntry $entry): bool
    {
        $entry->loadMissing('item');

        return $entry->item?->isLocalFixture() ?? false;
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
        $entry->loadMissing(['item', 'warehouse', 'materialConsumptions.item', 'materialConsumptions.warehouse', 'scraps.scrapReason']);

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
            'item' => $entry->item->name,
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
        $item = $this->scrapItem();

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
     * The exact Tally stock item scrap posts against, or null when the factory
     * has not named one.
     *
     * By SKU first, then by exact name — the same two-step the ERP's own scrap
     * receipt uses, so one setting drives both and they cannot name different
     * items. Never a pattern match: "Pet Scrap", "PET Scrap - Amber",
     * "PET Scrap - Lumps" and "Pet Bottles Scrap" all exist in this factory's
     * masters, and a near-miss books real weight against the wrong one.
     */
    private function scrapItem(): ?Item
    {
        $configured = config('production.scrap.rejected_item_sku');

        if ($configured === null || $configured === '') {
            return null;
        }

        return Item::query()->where('sku', $configured)->first()
            ?? Item::query()->where('name', $configured)->first();
    }

    /**
     * @return array{kind: string, item: ?string, quantity: string, unit: string, reason: string}|null
     */
    private function withheldScrapLine(ShiftProductionEntry $entry): ?array
    {
        // Nothing to withhold once the scrap line actually posts — the figure is
        // on the voucher, and a "held back" note beside a posted line would be
        // simply untrue.
        if ($this->scrapItem() !== null) {
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
            'reason' => 'This batch recorded '.implode(', ', $counted).'. No scrap line is posted to Tally because '
                .'the factory discards rejects and lumps (owner ruling, 05-Aug) — discarded material is not stock '
                .'anyone owns. The resin they used is already on the consumption line above; only the bottle is '
                .'thrown away, not the material that went into it.',
        ];
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
                // Local-only fixtures never join a voucher. Without this the
                // top-level guard would be bypassed sideways: a REAL item's
                // approval sweeps its approved shift-mates, and a fixture
                // sitting in the same shift would have its quantities posted
                // to Tally under someone else's approval.
                //
                // Written as "doesn't have a LOCAL item" rather than "has a
                // non-LOCAL item" because Item soft-deletes: an entry whose
                // product was retired after the run has NO visible item row,
                // and the positive form would silently drop its quantities
                // from the voucher. Absence of a LOCAL match is the correct
                // test — it includes exactly what the old code included.
                ->whereDoesntHave('item', fn ($query) => $query->where(
                    'sku', 'like', Item::LOCAL_FIXTURE_SKU_PREFIX.'%',
                ))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

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

                return $voucher;
            }

            ShiftProductionEntry::query()
                ->whereIn('id', $joining->pluck('id'))
                ->update(['tally_sync_entry_id' => $voucher->id]);

            // Rebuild the payload from ALL members (pre-existing + just
            // joined) — recomputing the sums beats patching incrementally.
            $members = ShiftProductionEntry::query()
                ->where('tally_sync_entry_id', $voucher->id)
                ->orderBy('id')
                ->get();
            $voucher->update([
                'payload' => $this->shiftVoucherPayload($members, $voucher->payload['voucher_number'], $entry),
                // Every merge re-arms the release gate's quiet period: the
                // voucher leaves only after it has sat untouched for the
                // configured idle-hold (DEC-20260807-011).
                'last_merged_at' => now(),
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

            $key = "{$member->item_id}@{$member->warehouse_id}";
            $produced[$key] = [
                'item' => $member->item->name,
                'quantity' => bcadd($produced[$key]['quantity'] ?? '0.0000', (string) $member->quantity_produced, 4),
                'godown' => $this->godowns->resolveName($member->warehouse),
            ];
        }

        $batches = $members->pluck('batch_number')->filter()->values();

        return [
            'voucher_type' => 'Stock Journal',
            'voucher_date' => $entry->production_date->toDateString(),
            'voucher_number' => $voucherNumber,
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

    public function pending(): Collection
    {
        $entries = TallySyncEntry::query()
            ->where('status', TallySyncStatus::Pending)
            ->orderBy('id')
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
        TallySyncEntry::query()
            ->whereIn('id', $entries->pluck('id'))
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        return $entries;
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return TallySyncEntry::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function markSynced(TallySyncEntry $entry): TallySyncEntry
    {
        $entry->update([
            'status' => TallySyncStatus::Synced,
            'synced_at' => now(),
            'error_message' => null,
        ]);

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
    public function markFailed(TallySyncEntry $entry, string $errorMessage): TallySyncEntry
    {
        if ($entry->isInTally()) {
            logger()->warning('Tally sync failure ignored: that voucher is already in Tally.', [
                'tally_sync_entry_id' => $entry->id,
                'voucher_number' => $entry->voucherNumber(),
                'synced_at' => $entry->synced_at?->toIso8601String(),
                'reported_error' => $errorMessage,
            ]);

            return $entry;
        }

        $entry->update([
            'status' => TallySyncStatus::Failed,
            'error_message' => $errorMessage,
            'attempts' => $entry->attempts + 1,
        ]);

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
     * @throws ValidationException 422 naming the voucher to check in Tally
     */
    public function retry(TallySyncEntry $entry, ?int $userId = null): TallySyncEntry
    {
        if ($entry->isInTally()) {
            $synced = $entry->synced_at?->format('d M Y H:i');

            throw ValidationException::withMessages([
                'entry' => "This voucher is already in Tally as {$entry->voucherNumber()}"
                    .($synced !== null ? " (synced {$synced})" : '')
                    .' — check Tally before anything else. Re-queueing it would post the same voucher twice.',
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
        $failedError = $entry->error_message;
        $payload = $this->regeneratePayload($entry) ?? $entry->payload;

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
        ]);

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
     * @throws ValidationException
     */
    public function releaseNow(TallySyncEntry $entry, ?int $userId = null): TallySyncEntry
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
            default => null,
        };

        if ($fresh === null) {
            return null;
        }

        // Keep the history the old payload accumulated.
        $fresh['resolution_log'] = $entry->payload['resolution_log'] ?? [];

        return $fresh;
    }

    private function enqueue(Model $syncable, string $voucherType, array $payload): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $syncable->getMorphClass(),
            'syncable_id' => $syncable->getKey(),
            'tally_voucher_type' => $voucherType,
            'payload' => $payload,
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }
}
