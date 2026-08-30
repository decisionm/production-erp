<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;

/**
 * The mapping state of ONE queued voucher, name by name, as the Control
 * Center's detail view shows it (MASTER-PLAN P3-04): for every line the
 * payload will hand Tally — its item and its godown — and for the ledgers
 * the voucher names (a Journal's lines, the party, the Sales ledger),
 * whether this ERP resolved that name by identity, by name only, or not at
 * all. Every STATE comes from LineMappingResolver, the same resolver the
 * pre-approval preview uses, so the preview and the show endpoint cannot
 * disagree about the STATE of a name; each surface's SENTENCES are its own
 * (this one carries the resolver's descriptive notes verbatim; the preview
 * writes blockers a person can act on), and both stay in the resolver's
 * register — what is recorded here, and what follows from Tally matching by
 * name — never a claim about what Tally will answer.
 *
 * WHICH KEYS ARE WALKED is decided by the entry's category
 * (TransactionClassifier::classify) — the one classification table — so
 * this class reads exactly the payload shape TallySyncService's builder for
 * that category writes (the key list quoted on TransactionClassifier):
 *
 *   production (shift / batch)  produced[{item, quantity[, godown]}] · consumed[{item, quantity, godown}] · godown
 *   Sales                       lines[{item, …}] · party_ledger · sales_ledger            (no godown)
 *   Receipt Note / Delivery Note lines[{item, …}] · party_ledger · godown (voucher-level)
 *   Purchase Order              lines[{item, …}] · party_ledger · purchase_ledger · godown (voucher-level)
 *   Journal                     lines[{ledger, …}]                                        (no item, no party)
 *   Unknown                     whatever of the above the payload actually carries
 *
 * A production line without a godown of its own (a batch voucher's produced
 * line) lands in the voucher-level godown, and so is judged against it —
 * the same reading VoucherPreviewService makes.
 *
 * FC-06 HAS TWO HALVES ("Purchase rates and supplier details are
 * Owner/Accounts only. Floor and sales logins never see what a material
 * cost or who supplied it"), and this block is judged against both:
 *
 *   - RATES: the block carries names, ids, GUIDs, states, counts and notes
 *     and NOTHING that could be a price, for EVERY reader. It is read by
 *     anyone with tally-sync.view, on the same resource whose payload rates
 *     are finance-gated; a rate reaching this block would undo that gate,
 *     so the walk copies no line key but `item`, `godown` and `ledger`.
 *   - SUPPLIER IDENTITY: the `party` row names the counter-party. On a
 *     Receipt Note (any category whose partyIsSupplier()) that is the
 *     VENDOR, so for a reader who may not read purchase details — the
 *     resource's ONE verdict, AgentIdentity::mayReadPurchaseDetails, the
 *     same predicate that gates the rates — the row is emitted as
 *     {name: null, state: 'withheld', note: why} rather than dropped, so
 *     the drawer can say why the row is empty. `withheld` is not a resolver
 *     state and is not counted in `mapping_summary` (the summary counts
 *     what the block shows). A customer on a Sales invoice or a Delivery
 *     Note is not FC-06 and always reads.
 */
class EntryMappingSurface
{
    /**
     * The party row's state for a reader who may not see the supplier — a
     * surface state, not a resolver one: nothing was resolved, the name
     * was withheld before resolution.
     */
    public const STATE_WITHHELD = 'withheld';

    public function __construct(
        private readonly TransactionClassifier $classifier,
        private readonly LineMappingResolver $resolver,
    ) {}

    /**
     * Both blocks the resource exposes, computed in one pass over the
     * payload: `mappings` (the per-name states) and `mapping_summary` (how
     * many names landed in each counted state — `none` is not counted,
     * nothing was there to map; nor is `withheld`, nothing was shown).
     *
     * @param  bool  $mayReadPurchaseDetails  the resource's ONE verdict
     *                                        (AgentIdentity::mayReadPurchaseDetails).
     *                                        Defaults to false — fail-closed:
     *                                        a caller that forgets to say
     *                                        withholds the vendor rather
     *                                        than leaking it.
     * @return array{
     *     mappings: array{
     *         lines: list<array{side: string, item: array<string, mixed>, godown: array<string, mixed>}>,
     *         ledgers: list<array{name: ?string, state: string, note: ?string}>,
     *         party: array{name: ?string, state: string, note: ?string}|null,
     *         sales_ledger: array{name: ?string, state: string, note: ?string}|null,
     *         purchase_ledger: array{name: ?string, state: string, note: ?string}|null,
     *     },
     *     mapping_summary: array<string, int>,
     * }
     */
    public function for(TallySyncEntry $entry, bool $mayReadPurchaseDetails = false): array
    {
        $payload = is_array($entry->payload) ? $entry->payload : [];
        $category = $this->classifier->classify($entry);
        $shape = $this->shape($category, $payload);

        $lines = [];
        foreach ($shape['stock'] as $section) {
            foreach ($this->rows($payload, $section) as $line) {
                $itemName = $this->name($line['item'] ?? null);
                // A line's own godown when it names one; the voucher's
                // otherwise; none when neither does (a Sales voucher).
                $godownName = $this->name($line['godown'] ?? null) ?? $this->name($payload['godown'] ?? null);

                $lines[] = [
                    'side' => $section,
                    'item' => ['name' => $itemName] + $this->resolver->item($itemName),
                    'godown' => ['name' => $godownName] + $this->resolver->godown($godownName),
                ];
            }
        }

        $ledgers = [];
        if ($shape['ledgers']) {
            foreach ($this->rows($payload, 'lines') as $line) {
                $ledgerName = $this->name($line['ledger'] ?? null);
                $ledgers[] = ['name' => $ledgerName] + $this->resolver->ledgerName($ledgerName);
            }
        }

        $party = null;
        if ($shape['party'] && $category->partyIsSupplier() && ! $mayReadPurchaseDetails) {
            // FC-06's second half: the vendor is not this reader's to see.
            // The row is kept, emptied and explained — a blank row would
            // read as "no party", which is a different (and false) claim.
            $party = [
                'name' => null,
                'state' => self::STATE_WITHHELD,
                'note' => 'The supplier on this voucher is withheld: supplier identity is Owner/Accounts only (FC-06).',
            ];
        } elseif ($shape['party']) {
            $partyName = $this->name($payload['party_ledger'] ?? null);
            $party = ['name' => $partyName] + $this->resolver->ledgerName($partyName);
        }

        // THE SALES LEDGER MOVED, and the role it is judged against moved with
        // it. Before 31-Aug-2026 the Sales payload carried ONE top-level
        // `sales_ledger` mapped from the single `sales` role. The GST-correct
        // payload writes the ledger PER LINE and chooses between two roles by
        // the supply type — `sales_local` (CGST+SGST) or `sales_interstate`
        // (IGST) — because the factory's own vouchers use exactly two ledgers,
        // 'Local Sales Taxable' and 'Interstate Sales Taxable'.
        //
        // Reading the old place still would show the mapping drawer a NAMELESS
        // row wearing a green "identity" badge derived from a vestigial role
        // nothing writes any more — and it would flip to "unmapped" if anyone
        // cleared that stale `sales` row, even though the real mappings were
        // correct. Both readings are lies about configuration.
        //
        // OLD QUEUED ROWS STILL READ CORRECTLY: a payload that carries the
        // top-level key is judged the old way, because that is genuinely what
        // it was staged from.
        $salesLedger = null;
        if ($shape['sales_ledger']) {
            if (array_key_exists('sales_ledger', $payload)) {
                $salesName = $this->name($payload['sales_ledger'] ?? null);
                $salesRole = TallyLedgerRole::Sales;
            } else {
                $firstLine = is_array($payload['lines'][0] ?? null) ? $payload['lines'][0] : [];
                $salesName = $this->name($firstLine['sales_ledger'] ?? null);
                $salesRole = ($payload['supply_type'] ?? null) === 'intra_state'
                    ? TallyLedgerRole::SalesLocal
                    : TallyLedgerRole::SalesInterstate;
            }

            $salesLedger = ['name' => $salesName] + $this->resolver->ledgerRole($salesRole, $salesName);
        }

        // The Purchase Order's purchase ledger (TallyLedgerRole::Purchase —
        // enqueuePurchaseOrder writes payload.purchase_ledger from the
        // mapping and refuses when there is none), judged the way the Sales
        // ledger is. Additive key: null on every other category.
        $purchaseLedger = null;
        if ($shape['purchase_ledger']) {
            $purchaseName = $this->name($payload['purchase_ledger'] ?? null);
            $purchaseLedger = ['name' => $purchaseName] + $this->resolver->ledgerRole(TallyLedgerRole::Purchase, $purchaseName);
        }

        $mappings = [
            'lines' => $lines,
            'ledgers' => $ledgers,
            'party' => $party,
            'sales_ledger' => $salesLedger,
            'purchase_ledger' => $purchaseLedger,
        ];

        return [
            'mappings' => $mappings,
            'mapping_summary' => $this->summarise($mappings),
        ];
    }

    /**
     * Which payload keys carry names for this category. The built
     * categories are stated outright — the shape is the builder's contract,
     * not a guess — and Unknown (a row the classifier could not place)
     * falls back to whatever the payload actually carries, so an odd row is
     * still described rather than shown blank.
     *
     * @param  array<string, mixed>  $payload
     * @return array{stock: list<string>, ledgers: bool, party: bool, sales_ledger: bool, purchase_ledger: bool}
     */
    private function shape(TallyTransactionCategory $category, array $payload): array
    {
        return match ($category) {
            TallyTransactionCategory::ProductionStockJournalShift,
            TallyTransactionCategory::ProductionStockJournalBatch => [
                'stock' => ['produced', 'consumed'], 'ledgers' => false, 'party' => false, 'sales_ledger' => false, 'purchase_ledger' => false,
            ],
            TallyTransactionCategory::SalesInvoice => [
                'stock' => ['lines'], 'ledgers' => false, 'party' => true, 'sales_ledger' => true, 'purchase_ledger' => false,
            ],
            TallyTransactionCategory::ReceiptNote,
            TallyTransactionCategory::DeliveryNote => [
                'stock' => ['lines'], 'ledgers' => false, 'party' => true, 'sales_ledger' => false, 'purchase_ledger' => false,
            ],
            TallyTransactionCategory::PurchaseOrder => [
                'stock' => ['lines'], 'ledgers' => false, 'party' => true, 'sales_ledger' => false, 'purchase_ledger' => true,
            ],
            TallyTransactionCategory::Journal => [
                'stock' => [], 'ledgers' => true, 'party' => false, 'sales_ledger' => false, 'purchase_ledger' => false,
            ],
            default => [
                'stock' => array_values(array_filter(
                    ['produced', 'consumed', 'lines'],
                    fn (string $section) => $this->rowsCarry($payload, $section, 'item'),
                )),
                'ledgers' => $this->rowsCarry($payload, 'lines', 'ledger'),
                'party' => array_key_exists('party_ledger', $payload),
                'sales_ledger' => array_key_exists('sales_ledger', $payload),
                'purchase_ledger' => array_key_exists('purchase_ledger', $payload),
            ],
        };
    }

    /**
     * Counts per counted state over every name the block judged — each
     * line's item AND godown, each ledger, the party, the sales ledger, the
     * purchase ledger.
     *
     * @param  array{lines: list<array<string, mixed>>, ledgers: list<array<string, mixed>>, party: ?array<string, mixed>, sales_ledger: ?array<string, mixed>, purchase_ledger: ?array<string, mixed>}  $mappings
     * @return array<string, int>
     */
    private function summarise(array $mappings): array
    {
        $summary = array_fill_keys(LineMappingResolver::COUNTED_STATES, 0);

        $states = [];
        foreach ($mappings['lines'] as $line) {
            $states[] = $line['item']['state'];
            $states[] = $line['godown']['state'];
        }
        foreach ($mappings['ledgers'] as $ledger) {
            $states[] = $ledger['state'];
        }
        foreach (['party', 'sales_ledger', 'purchase_ledger'] as $single) {
            if ($mappings[$single] !== null) {
                $states[] = $mappings[$single]['state'];
            }
        }

        foreach ($states as $state) {
            if (array_key_exists($state, $summary)) {
                $summary[$state]++;
            }
        }

        return $summary;
    }

    /**
     * The array rows under $section — only the rows that ARE arrays, so a
     * hand-written or half-built payload cannot make the walk throw.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $section): array
    {
        $rows = $payload[$section] ?? null;

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /** @param  array<string, mixed>  $payload */
    private function rowsCarry(array $payload, string $section, string $key): bool
    {
        foreach ($this->rows($payload, $section) as $row) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /** A non-empty string, else null — the resolver's idea of "no name". */
    private function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
