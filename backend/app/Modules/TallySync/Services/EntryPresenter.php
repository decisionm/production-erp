<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads a sync entry the way a person would say it — one headline and the
 * lines beneath it per transaction category, its timeline, and the honesty
 * flags where what the row implies is not what the wire does. Derived on
 * every read from the entry, its payload and its events; stored nowhere
 * (TALLY-SYNC-CHAIN.md §3 "Classification, derived not stored"). MASTER-PLAN
 * P3-02 (summary) and P3-03 (timeline).
 *
 * FC-06 HAS TWO HALVES and this class is judged against both. "Purchase
 * rates and supplier details are Owner/Accounts only. Floor and sales logins
 * never see what a material cost or who supplied it."
 *
 *   - RATES: what this class NEVER puts in a sentence — a rate, a line
 *     amount, a bill total, a debit or a credit figure. The summary and the
 *     timeline are read by anyone holding tally-sync.view — they are NOT
 *     behind the finance gate TallySyncEntryResource puts on the payload —
 *     so every string built here is quantities and counts only, never
 *     money, for EVERY reader. The payload keys read for that are the
 *     quantity/name keys of TransactionClassifier's builder table (item,
 *     quantity, godown, ledger, shift, batch_number, entry_ids); `rate`,
 *     `amount`, `total_amount`, `debit`, `credit` are consulted for nothing
 *     but the SIDE of a journal line, and never printed.
 *   - SUPPLIER IDENTITY: the party segment of the headline names the
 *     counter-party. On a Receipt Note (any category whose
 *     partyIsSupplier()) that is the VENDOR, so summary() takes the
 *     resource's one verdict (AgentIdentity::mayReadPurchaseDetails — the
 *     SAME predicate that gates the payload's rates) and LEAVES THE SEGMENT
 *     OUT for a reader who may not. A customer on a Sales invoice or a
 *     Delivery Note is not FC-06 and always reads. This class does not
 *     look at the request itself: the resource judges once and passes the
 *     boolean, so summary and payload can never disagree about one reader.
 *
 * Nothing here queries Tally, the syncable, or any other module. The one
 * relation touched is the entry's own events (loaded by the show endpoint;
 * fetched here only if a caller asks for a timeline on an unloaded entry),
 * and the release gate — the same object the resource's `hold` key already
 * consults, so "held" here can never disagree with `hold` there.
 */
class EntryPresenter
{
    /**
     * Where each agent builder's own warning lives — quoted below, verbatim,
     * so the flag says what the code says and not a paraphrase of it. All
     * four non-production builders open with the SAME line ("BEST-EFFORT
     * TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE"; read them,
     * they are not edited from here); the production builders do not.
     */
    private const SALES_BUILDER = 'tally-sync-agent/src/tally/voucherBuilders/salesInvoice.ts';

    private const RECEIPT_NOTE_BUILDER = 'tally-sync-agent/src/tally/voucherBuilders/receiptNote.ts';

    private const DELIVERY_NOTE_BUILDER = 'tally-sync-agent/src/tally/voucherBuilders/deliveryNote.ts';

    private const JOURNAL_BUILDER = 'tally-sync-agent/src/tally/voucherBuilders/journalEntry.ts';

    /** The line every one of the four carries, verbatim (salesInvoice.ts:19, receiptNote.ts:17, deliveryNote.ts:17, journalEntry.ts:13). */
    private const UNVALIDATED_LINE = 'BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE';

    /**
     * The entry columns that stand in for an event when no event exists —
     * the pre-history rows the backfill migration did not reach (an entry
     * whose live history began after the recorder shipped is skipped by
     * that migration, so its enqueue has only created_at to vouch for it).
     * Column → the event kind the live recorder writes at that instant.
     */
    private const TIMESTAMP_EVENTS = [
        'created_at' => TallySyncEventKind::VoucherEnqueued,
        'released_at' => TallySyncEventKind::VoucherReleased,
        'delivered_at' => TallySyncEventKind::PendingDelivered,
        'synced_at' => TallySyncEventKind::VoucherSynced,
    ];

    /**
     * How close an event's occurred_at must be to a column's value to count
     * as the SAME instant. Every recording site stamps the column and the
     * event with two separate now() calls inside one request, so at second
     * precision they can straddle a boundary; a few seconds' tolerance
     * absorbs that without ever pairing a re-delivery with last week's.
     */
    private const SAME_INSTANT_SECONDS = 5;

    public function __construct(
        private readonly TransactionClassifier $classifier,
        private readonly ShiftVoucherReleaseGate $releaseGate,
    ) {}

    // ---- summary ------------------------------------------------------------

    /**
     * One headline and the lines beneath it. The headline is segments joined
     * by " · ", in a fixed order so every category reads alike:
     *
     *   <what it is + document number> · <party | shift | batch> · <business date> · <counts> · <status>
     *
     * A segment the payload cannot fill is left out, never invented (a
     * journal has no party; an unclassified entry has no counts) — and so
     * is the party segment of a supplier-party voucher for a reader who may
     * not read purchase details (FC-06, class docblock).
     *
     * @param  bool  $mayReadPurchaseDetails  the resource's ONE verdict
     *                                        (AgentIdentity::mayReadPurchaseDetails).
     *                                        Defaults to false — fail-closed:
     *                                        a caller that forgets to say
     *                                        withholds the vendor rather
     *                                        than leaking it.
     * @return array{headline: string, lines: list<string>}
     */
    public function summary(TallySyncEntry $entry, bool $mayReadPurchaseDetails = false): array
    {
        $category = $this->classifier->classify($entry);
        $payload = is_array($entry->payload) ? $entry->payload : [];

        $segments = [$this->whatItIs($entry, $category)];

        $who = match ($category) {
            TallyTransactionCategory::ProductionStockJournalShift => $this->prefixed('Shift ', $this->string($payload, 'shift')),
            TallyTransactionCategory::ProductionStockJournalBatch => $this->prefixed('batch ', $this->string($payload, 'batch_number')),
            // The vendor on a Receipt Note is FC-06's "who supplied it":
            // left out (not blanked, not replaced) unless the reader may.
            default => $category->partyIsSupplier() && ! $mayReadPurchaseDetails ? null : $this->classifier->party($entry),
        };
        if ($who !== null) {
            $segments[] = $who;
        }

        $date = $this->classifier->businessDate($entry);
        if ($date !== null) {
            $segments[] = $this->humanDate($date);
        }

        foreach ($this->countSegments($category, $payload) as $segment) {
            $segments[] = $segment;
        }

        $segments[] = $this->statusPhrase($entry);

        return [
            'headline' => implode(' · ', $segments),
            'lines' => $this->lines($category, $payload),
        ];
    }

    /**
     * The opening words: what Tally RECEIVES (the wire voucher type) plus
     * the document number — so a per-batch production voucher reads "Stock
     * Journal SPE-9" like the shift one does, and the ERP's own
     * "Manufacturing Journal" label rides the flags instead. Unknown says
     * so in plain words rather than borrowing a type it cannot prove.
     */
    private function whatItIs(TallySyncEntry $entry, TallyTransactionCategory $category): string
    {
        $number = $this->classifier->documentNumber($entry);

        $words = $category === TallyTransactionCategory::Unknown
            ? "Unclassified voucher type '{$entry->tally_voucher_type}'"
            : ($category->wireVoucherType() ?? $entry->tally_voucher_type);

        return $number === null ? $words : "{$words} {$number}";
    }

    /**
     * The count words per category — what the voucher MOVES, never what it
     * is worth. A single-line voucher also states its quantity ("1 item ×
     * 200") because that is the figure the accountant checks against Tally;
     * with more than one line the quantities belong to the lines, not the
     * headline (they may be in different units and must not be summed).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function countSegments(TallyTransactionCategory $category, array $payload): array
    {
        switch ($category) {
            case TallyTransactionCategory::ProductionStockJournalShift:
                $segments = [];
                $batches = $payload['entry_ids'] ?? null;
                if (is_array($batches) && $batches !== []) {
                    $segments[] = $this->plural(count($batches), 'batch', 'batches');
                }
                $segments[] = $this->producedConsumed($payload);

                return $segments;

            case TallyTransactionCategory::ProductionStockJournalBatch:
                return [$this->producedConsumed($payload)];

            case TallyTransactionCategory::Journal:
                return [$this->plural(count($this->rows($payload, 'lines')), 'ledger line', 'ledger lines')];

            case TallyTransactionCategory::SalesInvoice:
            case TallyTransactionCategory::DeliveryNote:
            case TallyTransactionCategory::ReceiptNote:
                $lines = $this->rows($payload, 'lines');
                $names = array_values(array_unique(array_filter(array_map(
                    fn (array $line) => is_string($line['item'] ?? null) ? $line['item'] : null,
                    $lines,
                ))));
                $words = $this->plural(count($names), 'item', 'items');
                if (count($lines) === 1 && isset($lines[0]['quantity'])) {
                    $words .= ' × '.$this->quantity($lines[0]['quantity']);
                }

                return [$words];

            default:
                return [];
        }
    }

    /** "produced 2 items, consumed 4" — line counts on each side of a stock journal. */
    private function producedConsumed(array $payload): string
    {
        $produced = count($this->rows($payload, 'produced'));
        $consumed = count($this->rows($payload, 'consumed'));

        return "produced {$this->plural($produced, 'item', 'items')}, consumed {$consumed}";
    }

    /**
     * The lines beneath the headline, one string each. Item × quantity for
     * stock lines, with the godown and the direction where the payload
     * names one (→ into, ← out of); side + ledger name for a journal — the
     * debit/credit FIGURES are read only to choose "Dr"/"Cr" and are never
     * printed. A production voucher's withheld lines are stated too,
     * because an absence explains nothing (the payload's own words).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function lines(TallyTransactionCategory $category, array $payload): array
    {
        return match ($category) {
            TallyTransactionCategory::ProductionStockJournalShift,
            TallyTransactionCategory::ProductionStockJournalBatch => [
                ...array_map(fn (array $line) => $this->stockLine('Produced: ', $line, ' → ', $payload), $this->rows($payload, 'produced')),
                ...array_map(fn (array $line) => $this->stockLine('Consumed: ', $line, ' ← ', $payload), $this->rows($payload, 'consumed')),
                ...array_map(fn (array $line) => $this->withheldLine($line), $this->rows($payload, 'withheld')),
            ],
            TallyTransactionCategory::Journal => array_map(fn (array $line) => $this->journalLine($line), $this->rows($payload, 'lines')),
            // A receipt lands IN its godown, a delivery leaves FROM its; a
            // Sales invoice's payload names no godown, so its lines name none.
            TallyTransactionCategory::ReceiptNote => array_map(fn (array $line) => $this->stockLine('', $line, ' → ', $payload), $this->rows($payload, 'lines')),
            TallyTransactionCategory::DeliveryNote => array_map(fn (array $line) => $this->stockLine('', $line, ' ← ', $payload), $this->rows($payload, 'lines')),
            TallyTransactionCategory::SalesInvoice => array_map(fn (array $line) => $this->stockLine('', $line, null, $payload), $this->rows($payload, 'lines')),
            default => [],
        };
    }

    /** @param  array<string, mixed>  $line */
    private function stockLine(string $prefix, array $line, ?string $arrow, array $payload): string
    {
        $text = $prefix.($this->string($line, 'item') ?? '?');
        if (isset($line['quantity'])) {
            $text .= ' × '.$this->quantity($line['quantity']);
        }
        // A production line's godown, or the voucher-level one it falls
        // back to on the wire (manufacturingJournal.ts / stockJournal.ts).
        $godown = $this->string($line, 'godown') ?? $this->string($payload, 'godown');
        if ($arrow !== null && $godown !== null) {
            $text .= $arrow.$godown;
        }

        return $text;
    }

    /** @param  array<string, mixed>  $line */
    private function journalLine(array $line): string
    {
        $ledger = $this->string($line, 'ledger') ?? '?';
        $debit = is_numeric($line['debit'] ?? null) ? (float) $line['debit'] : 0.0;
        $credit = is_numeric($line['credit'] ?? null) ? (float) $line['credit'] : 0.0;

        return match (true) {
            $debit > 0 => "Dr {$ledger}",
            $credit > 0 => "Cr {$ledger}",
            default => $ledger,
        };
    }

    /** @param  array<string, mixed>  $line  {kind, item, quantity, unit, reason} as PackingVoucherLines writes it */
    private function withheldLine(array $line): string
    {
        $what = $this->string($line, 'item') ?? $this->string($line, 'kind') ?? 'line';
        $text = "Withheld (not posted): {$what}";
        if (isset($line['quantity'])) {
            $text .= ' × '.$this->quantity($line['quantity']).($this->string($line, 'unit') !== null ? " {$line['unit']}" : '');
        }

        return $text;
    }

    /**
     * Where the voucher is in its life, in the words the page uses. A held
     * shift voucher says why (the release gate's phase — the same call the
     * resource's `hold` makes, never a second reading of the gate); a
     * pending voucher distinguishes "not yet handed out" from "with the
     * agent, no answer yet" by delivered_at, exactly as the list does.
     */
    private function statusPhrase(TallySyncEntry $entry): string
    {
        if ($entry->status === TallySyncStatus::Pending) {
            $hold = $this->releaseGate->hold($entry);
            if ($hold !== null) {
                return $hold['phase'] === 'collecting' ? 'held — shift still collecting' : 'held — quiet period';
            }

            return $entry->delivered_at !== null ? 'with the agent' : 'waiting for agent';
        }

        return match ($entry->status) {
            TallySyncStatus::Synced => 'in Tally',
            TallySyncStatus::Failed => 'failed'.($entry->attempts > 0 ? " (attempt {$entry->attempts})" : ''),
            TallySyncStatus::Dismissed => 'dismissed',
        };
    }

    // ---- timeline -----------------------------------------------------------

    /**
     * What happened to this voucher, oldest first, one shape per row for
     * every category: the append-only events (tally_sync_events) PLUS the
     * entry's own timestamps where no event stands for them, de-duplicated
     * — an event at the same instant wins, and a row that is a
     * reconstruction (a backfilled event, or a column read now) is flagged
     * so nobody reads it as an observation.
     *
     * `source` says which of the three it is: 'event' (recorded as it
     * happened), 'backfill' (the migration's reconstruction from the
     * columns, persisted), 'timestamp' (this read's reconstruction from a
     * column no event covers). `backfilled` is true for both reconstructions.
     *
     * @return list<array{at: ?string, event: string, actor_type: ?string, actor_label: ?string, detail: ?string, source: 'event'|'backfill'|'timestamp', backfilled: bool}>
     */
    /**
     * @param  bool  $mayReadPurchaseDetails  FC-06 second half: when false and
     *                                        the voucher's party is a supplier,
     *                                        Tally's rejection text — which can
     *                                        name that supplier — is left off
     *                                        the Failed / Refused / Retried /
     *                                        Dismissed rows; the events stay.
     */
    public function timeline(TallySyncEntry $entry, bool $mayReadPurchaseDetails = false): array
    {
        $withholdsSupplier = ! $mayReadPurchaseDetails && $this->classifier->classify($entry)->partyIsSupplier();
        /** @var Collection<int, TallySyncEvent> $events */
        $events = $entry->relationLoaded('events') ? $entry->events : $entry->events()->get();

        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'at' => $event->occurred_at?->toIso8601String(),
                'event' => $event->event,
                'actor_type' => $event->actor_type,
                'actor_label' => $event->actor_label,
                'detail' => $this->eventDetail($event, $withholdsSupplier),
                'source' => $event->isBackfilled() ? 'backfill' : 'event',
                'backfilled' => $event->isBackfilled(),
                // Sort keys, stripped below: the instant, then event ids in
                // recording order, with derived rows after real ones at a tie.
                '_ts' => $event->occurred_at?->getTimestamp() ?? PHP_INT_MIN,
                '_order' => $event->id,
            ];
        }

        foreach (self::TIMESTAMP_EVENTS as $column => $kind) {
            $at = $entry->{$column};
            if (! $at instanceof CarbonInterface) {
                continue;
            }

            if ($this->hasEventAt($events, $kind, $at)) {
                continue;
            }

            $rows[] = [
                'at' => $at->toIso8601String(),
                'event' => $kind->value,
                'actor_type' => null,
                'actor_label' => null,
                'detail' => "From the entry's {$column} — no event was recorded for it, so who did it is not known.",
                'source' => 'timestamp',
                'backfilled' => true,
                '_ts' => $at->getTimestamp(),
                '_order' => PHP_INT_MAX,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['_ts'], $a['_order']] <=> [$b['_ts'], $b['_order']]);

        return array_map(fn (array $row) => array_diff_key($row, ['_ts' => 0, '_order' => 0]), $rows);
    }

    /** @param  Collection<int, TallySyncEvent>  $events */
    private function hasEventAt(Collection $events, TallySyncEventKind $kind, CarbonInterface $at): bool
    {
        return $events->contains(fn (TallySyncEvent $event) => $event->event === $kind->value
            && $event->occurred_at !== null
            && abs($event->occurred_at->getTimestamp() - $at->getTimestamp()) <= self::SAME_INSTANT_SECONDS);
    }

    /**
     * One sentence per event kind from its details — the facts the entry's
     * columns lose (each failure's error, whether a retry regenerated the
     * payload, which entries joined a merge). Details never carry a rate
     * (TALLY-SYNC-CHAIN.md §4), so nothing rendered here can. An event kind
     * this method does not know renders no sentence rather than a guess.
     */
    private function eventDetail(TallySyncEvent $event, bool $withholdsSupplier = false): ?string
    {
        $details = is_array($event->details) ? $event->details : [];
        $kind = TallySyncEventKind::tryFrom($event->event);
        // Tally's own words can name the supplier (FC-06, second half); for a
        // reader without standing on a supplier-party voucher they are left
        // off — the row still says the voucher failed / was retried.
        $error = $withholdsSupplier ? null : $this->string($details, 'error_message');
        $previous = $withholdsSupplier ? null : $this->string($details, 'previous_error');

        return match ($kind) {
            TallySyncEventKind::VoucherEnqueued => $this->joinSentence([
                'Queued'.$this->prefixed(' as ', $this->string($details, 'voucher_type')).$this->prefixed(' ', $this->string($details, 'voucher_number')),
                is_array($details['entry_ids'] ?? null) ? 'carrying '.$this->plural(count($details['entry_ids']), 'entry', 'entries') : null,
                isset($details['follow_up_of']) ? "as a follow-up of voucher #{$details['follow_up_of']}" : null,
            ]),
            TallySyncEventKind::VoucherMerged => is_array($details['joined_entry_ids'] ?? null)
                ? $this->plural(count($details['joined_entry_ids']), 'entry', 'entries').' joined the voucher and its payload was rebuilt.'
                : 'Entries joined the voucher and its payload was rebuilt.',
            TallySyncEventKind::VoucherRebuilt => 'Rebuilt'.$this->prefixed(' on ', $this->string($details, 'rebuild'))
                .(is_array($details['excluded_entry_ids'] ?? null)
                    ? ' with '.$this->plural(count($details['excluded_entry_ids']), 'local-only fixture entry', 'local-only fixture entries').' left off the lines.'
                    : '.'),
            TallySyncEventKind::PendingDelivered => 'Handed to the agent.',
            TallySyncEventKind::VoucherSynced => 'The agent reported that Tally accepted it.',
            TallySyncEventKind::VoucherFailed => 'The agent reported that Tally rejected it'
                .(isset($details['attempt']) ? " (attempt {$details['attempt']})" : '')
                .($error !== null ? ": {$error}" : '.'),
            TallySyncEventKind::VoucherFailureRefused => 'A failure report arrived for a voucher already in Tally and was refused'
                .($error !== null ? ": {$error}" : '.'),
            TallySyncEventKind::VoucherRetried => 'Re-queued'
                .(($details['payload_regenerated'] ?? false) ? ' with the payload regenerated from current mappings' : ' with the frozen payload')
                .($previous !== null ? " — after: {$previous}" : '.'),
            TallySyncEventKind::VoucherDismissed => 'Written off — will never be sent to Tally'
                .($previous !== null ? " (last error: {$previous})" : '.'),
            TallySyncEventKind::VoucherReleased => 'Released ahead of the gate by a person.',
            // Counts and the hash prefix only — never Tally's text or the XML;
            // those live on the snapshot itself, gated by reader there.
            TallySyncEventKind::SnapshotStored => 'The agent uploaded what it sent and what Tally answered'
                .$this->prefixed(' — ', implode(' · ', array_filter([
                    isset($details['attempt']) ? 'attempt '.(int) $details['attempt'] : null,
                    is_string($details['xml_sha256'] ?? null) ? 'sha256 '.substr($details['xml_sha256'], 0, 12) : null,
                    isset($details['xml_bytes']) ? ((int) $details['xml_bytes']).' bytes' : null,
                    array_key_exists('tally_success', $details) && $details['tally_success'] !== null
                        ? ($details['tally_success'] ? 'Tally accepted' : 'Tally rejected')
                        : null,
                ])) ?: null).'.',
            default => null,
        };
    }

    // ---- flags --------------------------------------------------------------

    /**
     * The honesty flags: each key is present ONLY when raised, and its
     * value always carries a `note` (the sentence to show) plus the facts
     * behind it. Nothing here carries a price. Cheap enough for the list —
     * classification and payload reads cost no query, and the gate call
     * shares the syncable the resource's `hold` already loaded.
     *
     *   unvalidated_builder          the agent's builder for this category says, in its own docblock, that it is unvalidated
     *                                (all four non-production builders do; Sales additionally names the GST gap and DEC-20260809-003)
     *   order_reference_not_emitted  a Receipt Note carrying tally_order_no / order_due_dates the agent's builder does not emit
     *   label_differs_from_wire      the ERP's label is not the voucher type Tally receives
     *   held                         the release gate is holding this shift voucher right now
     *
     * @return array<string, array<string, mixed>>
     */
    public function flags(TallySyncEntry $entry): array
    {
        $category = $this->classifier->classify($entry);
        $payload = is_array($entry->payload) ? $entry->payload : [];
        $flags = [];

        // Each builder's own words, not ours — the four non-production
        // builders all open with the same unvalidated line, so all four
        // categories carry the flag, each naming its own file. Sales
        // additionally quotes its GST gap (salesInvoice.ts:28-32) and the
        // decision that keeps real sales in Tally (DEC-20260809-003): a
        // warning against use, not an invitation to fix and post. The
        // production builders (manufacturingJournal.ts / stockJournal.ts)
        // carry no such line and are not flagged.
        $unvalidated = match ($category) {
            TallyTransactionCategory::SalesInvoice => [
                'note' => 'The agent\'s Sales voucher builder is, in its own words, a "'.self::UNVALIDATED_LINE.'" that '
                    .'"doesn\'t yet emit GST tax ledger entries (CGST/SGST/IGST)". Real sales are invoiced in Tally '
                    .'(DEC-20260809-003); do not post real invoices from the ERP while that decision stands.',
                'builder' => self::SALES_BUILDER,
                'decision' => 'DEC-20260809-003',
            ],
            TallyTransactionCategory::ReceiptNote => [
                'note' => 'The agent\'s Receipt Note voucher builder is, in its own words, a "'.self::UNVALIDATED_LINE.'" — '
                    .'"Reverse-engineer against a real export before trusting it": ISDEEMEDPOSITIVE / batch / godown tag '
                    .'names vary by Tally version.',
                'builder' => self::RECEIPT_NOTE_BUILDER,
            ],
            TallyTransactionCategory::DeliveryNote => [
                'note' => 'The agent\'s Delivery Note voucher builder is, in its own words, a "'.self::UNVALIDATED_LINE.'" — '
                    .'"Validate the tag structure against a real export".',
                'builder' => self::DELIVERY_NOTE_BUILDER,
            ],
            TallyTransactionCategory::Journal => [
                'note' => 'The agent\'s Journal voucher builder is, in its own words, a "'.self::UNVALIDATED_LINE.'" — '
                    .'"more likely to be close to correct as-is, but still confirm against a real export before trusting it '
                    .'in production".',
                'builder' => self::JOURNAL_BUILDER,
            ],
            default => null,
        };
        if ($unvalidated !== null) {
            $flags['unvalidated_builder'] = $unvalidated;
        }

        // TallySyncService::enqueueGoodsReceiptNote() rides tally_order_no /
        // order_due_dates on the payload "as recorded facts"; receiptNote.ts
        // declares neither in ReceiptNotePayload and writes neither into
        // the XML (no ORDERNO / ORDERDUEDATE tags) — verified by reading the
        // builder, not assumed. Raised only when there IS a reference to lose.
        if ($category === TallyTransactionCategory::ReceiptNote) {
            $orderNo = $this->string($payload, 'tally_order_no');
            $dueDates = is_array($payload['order_due_dates'] ?? null) ? count($payload['order_due_dates']) : 0;

            if ($orderNo !== null || $dueDates > 0) {
                $flags['order_reference_not_emitted'] = [
                    'note' => 'This receipt carries a Tally order reference on its payload'
                        .($orderNo !== null ? " (order {$orderNo}" : ' (')
                        .($dueDates > 0 ? ($orderNo !== null ? ', ' : '').$this->plural($dueDates, 'due-date allocation', 'due-date allocations') : '')
                        .'), but the agent\'s Receipt Note builder (receiptNote.ts) neither declares nor emits it — '
                        .'the order reference does not reach Tally with this voucher.',
                    'builder' => self::RECEIPT_NOTE_BUILDER,
                    'tally_order_no' => $orderNo,
                    'order_due_dates' => $dueDates,
                ];
            }
        }

        if ($category->erpLabelDiffersFromWire()) {
            $flags['label_differs_from_wire'] = [
                'note' => "Labelled '{$entry->tally_voucher_type}' on this entry and in the ERP; posted to Tally as a "
                    ."{$category->wireVoucherType()} (the agent's builder emits <VOUCHERTYPENAME>{$category->wireVoucherType()}</VOUCHERTYPENAME>).",
                'erp_label' => $entry->tally_voucher_type,
                'wire_voucher_type' => $category->wireVoucherType(),
            ];
        }

        $hold = $this->releaseGate->hold($entry);
        if ($hold !== null) {
            $flags['held'] = [
                'note' => ($hold['phase'] === 'collecting'
                    ? 'Held by the release gate — the shift is still collecting'
                    : 'Held by the release gate — quiet period after the last merge')
                    .'; releasable at '.$hold['releasable_at']->toIso8601String().' (an estimate: a later merge pushes it out).',
                'phase' => $hold['phase'],
                'releasable_at' => $hold['releasable_at']->toIso8601String(),
            ];
        }

        return $flags;
    }

    // ---- small helpers ------------------------------------------------------

    /**
     * The list rows at $key of the payload — arrays only; anything else
     * (a missing key, a scalar, a malformed line) is skipped, not guessed.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param  array<string, mixed>  $source */
    private function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function prefixed(string $prefix, ?string $value): ?string
    {
        return $value === null ? null : $prefix.$value;
    }

    /** "1 item" / "3 items". */
    private function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }

    /** @param  list<?string>  $parts */
    private function joinSentence(array $parts): string
    {
        return implode(' ', array_filter($parts, fn (?string $part) => $part !== null && $part !== '')).'.';
    }

    /**
     * A stock quantity as a person writes it: the stored decimal with its
     * trailing zeros dropped ("100.0000" → "100", "96.5000" → "96.5"). No
     * unit is appended — the payload carries none, and one is not invented.
     */
    private function quantity(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '?';

        if (is_numeric($text) && str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /** "2026-08-16" → "16-Aug-2026"; anything that is not that shape passes through as it is. */
    private function humanDate(string $date): string
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            return $date;
        }

        return $parsed instanceof CarbonInterface && $parsed->format('Y-m-d') === $date ? $parsed->format('d-M-Y') : $date;
    }
}
