<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyInvoiceMatchState;
use App\Modules\TallySync\Models\TallySalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * IMPORTS Tally's Sales vouchers and matches them to ERP sales orders.
 *
 * THE DIRECTION. DEC-20260831-012: the ERP sends no Sales Order, no Delivery
 * Note and no Sales Invoice to Tally. Tally creates the Sales Invoice, the
 * e-invoice and the e-way details. This class is the whole of the sales
 * integration in the surviving direction — inbound, read-only towards Tally.
 *
 * THE MATCH KEY, AND WHY IT IS THIS ONE. The customer's own purchase-order
 * string, plus the customer. The factory's 31-Aug export proves it joins:
 * Tally's Sales voucher carries that string in BASICPURCHASEORDERNO (and
 * echoes it in REFERENCE and ORDERNO), its Sales Order voucher carries the
 * identical string in ORDERNO, and 32 of 55 Sales vouchers join to a Sales
 * Order with ZERO ambiguous keys. The ERP already records the same string on
 * `sales_orders.customer_po_reference`.
 *
 * VOUCHER NUMBER IS NOT THE KEY and must never become it. Tally owns a
 * contiguous NNN/26-27 series; the ERP mints INV-{id}. The two series never
 * meet, and matching on them would silently pair unrelated documents.
 *
 * WHAT IT REFUSES TO DO. It never creates a customer, never creates a sales
 * order, and never picks between two candidate orders. Each of those would be
 * inventing a commercial fact to make an import look tidy. An unmatched
 * voucher is RECORDED as unmatched, with which thing was missing named on the
 * row, and a person resolves it.
 *
 * RE-IMPORT IS A NO-OP. Tally's GUID is the row's identity, so running the
 * same export twice updates the same rows instead of doubling them — which
 * matters because the natural way to use this is to re-export the month.
 */
final class TallySalesInvoiceImporter
{
    /** Tally's name for the voucher type we read. Sales ORDER vouchers are ignored. */
    private const VOUCHER_TYPE = 'Sales';

    public function __construct(private readonly TallyVoucherXmlReader $reader) {}

    /**
     * @return array{
     *   read: int,
     *   matched: int,
     *   unmatched: int,
     *   written: bool,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function import(string $raw, bool $write): array
    {
        $vouchers = $this->reader->vouchers($raw, self::VOUCHER_TYPE);

        $rows = [];
        foreach ($vouchers as $voucher) {
            $rows[] = $this->assess($voucher);
        }

        if ($write) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    $this->persist($row);
                }
            });
        }

        $matched = count(array_filter($rows, fn ($r) => $r['match_state']->isMatched()));

        return [
            'read' => count($rows),
            'matched' => $matched,
            'unmatched' => count($rows) - $matched,
            'written' => $write,
            'rows' => $rows,
        ];
    }

    /**
     * Read one voucher and decide what it matches — WITHOUT writing anything.
     *
     * Separated from persist() so a dry run and a write run reach the identical
     * verdict. A dry run whose conclusions differ from the write that follows
     * it is worse than no dry run at all (AGENTS.md: dry-run first, write only
     * after reading it).
     *
     * @return array<string, mixed>
     */
    private function assess(\SimpleXMLElement $voucher): array
    {
        $guid = $this->tag($voucher, 'GUID');
        $number = $this->tag($voucher, 'VOUCHERNUMBER');
        $party = $this->tag($voucher, 'PARTYLEDGERNAME') ?? $this->tag($voucher, 'PARTYNAME');
        $reference = $this->orderReference($voucher);

        $base = [
            'tally_guid' => $guid ?? ('novchguid:'.$number.':'.$party),
            'voucher_number' => (string) $number,
            'voucher_date' => $this->date($voucher),
            'party_ledger_name' => (string) $party,
            'customer_po_reference' => $reference,
            'amount' => $this->partyAmount($voucher),
            'customer_id' => null,
            'sales_order_id' => null,
        ];

        if ($reference === null) {
            return $this->verdict(
                $base,
                TallyInvoiceMatchState::UnmatchedNoReference,
                'Tally voucher '.$number.' carries no BASICPURCHASEORDERNO.',
            );
        }

        // The link from a Tally party ledger to an ERP customer is the one
        // written by `sales:import-customers-from-ledgers` and by nothing else.
        // Matching on customer NAME instead would pair a voucher with whichever
        // ERP row happens to be spelled the same, which is not the same claim.
        $customer = Customer::query()
            ->where('tally_ledger_name', $party)
            ->first();

        if ($customer === null) {
            return $this->verdict(
                $base,
                TallyInvoiceMatchState::UnmatchedNoCustomer,
                'No ERP customer is linked to Tally ledger "'.$party.'".',
            );
        }

        $base['customer_id'] = $customer->id;

        $candidates = SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->where('customer_po_reference', $reference)
            ->where('status', '!=', SalesOrderStatus::Cancelled)
            ->get();

        if ($candidates->isEmpty()) {
            return $this->verdict(
                $base,
                TallyInvoiceMatchState::UnmatchedNoOrder,
                'No live sales order for '.$customer->name.' carries reference "'.$reference.'".',
            );
        }

        if ($candidates->count() > 1) {
            return $this->verdict(
                $base,
                TallyInvoiceMatchState::Ambiguous,
                $candidates->count().' sales orders carry reference "'.$reference.'": #'
                    .$candidates->pluck('id')->implode(', #').'. Refused rather than picking one.',
            );
        }

        $base['sales_order_id'] = $candidates->first()->id;

        return $this->verdict($base, TallyInvoiceMatchState::Matched, null);
    }

    /**
     * Stamp a verdict onto a half-built row.
     *
     * A helper rather than `$base + [...]`, which is what this was and was
     * wrong. PHP's array-union operator keeps the LEFT operand for duplicate
     * keys, so the `sales_order_id => null` already sitting in $base survived
     * the union that was supposed to replace it with the order just matched.
     * Every voucher reported "matched" and every persisted row pointed at no
     * order. The verdict looked right; only the saved row was wrong.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function verdict(array $row, TallyInvoiceMatchState $state, ?string $detail): array
    {
        $row['match_state'] = $state;
        $row['match_detail'] = $detail;

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function persist(array $row): void
    {
        TallySalesInvoice::query()->updateOrCreate(
            ['tally_guid' => $row['tally_guid']],
            $row + ['imported_at' => Carbon::now()],
        );
    }

    /**
     * The customer's purchase-order string.
     *
     * BASICPURCHASEORDERNO is the field Tally fills from the order reference,
     * present on 49 of the factory's 55 Sales vouchers. REFERENCE carries the
     * identical string on every voucher that has both, and is read as a
     * fallback for the handful that carry one and not the other. ORDERNO is
     * NOT read: on referenceless vouchers Tally fills it with the literal
     * "Not Applicable", which is a sentence, not a reference.
     */
    private function orderReference(\SimpleXMLElement $voucher): ?string
    {
        foreach (['BASICPURCHASEORDERNO', 'REFERENCE'] as $tag) {
            $value = trim((string) ($this->tag($voucher, $tag) ?? ''));

            if ($value !== '' && ! str_contains($value, 'Not Applicable')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The invoice total, taken from the PARTY ledger line.
     *
     * In all 54 party-bearing vouchers of the factory's export the party line
     * carries the signed grand total (negative, being a debtor debit) while the
     * sales and tax ledger lines carry the split. Reading the party line is
     * therefore the one place the whole invoice value appears once; summing the
     * others would re-derive it and could disagree over rounding.
     *
     * Absent or unreadable, this stays NULL. An invoice amount is a factory
     * money figure and is reported missing rather than reconstructed.
     */
    private function partyAmount(\SimpleXMLElement $voucher): ?string
    {
        $party = (string) ($this->tag($voucher, 'PARTYLEDGERNAME') ?? '');

        foreach ($voucher->xpath('.//ALLLEDGERENTRIES.LIST') ?: [] as $entry) {
            if ((string) ($entry->LEDGERNAME ?? '') !== $party) {
                continue;
            }

            $amount = trim((string) ($entry->AMOUNT ?? ''));

            if ($amount === '' || ! is_numeric($amount)) {
                return null;
            }

            // The party line is negative in Tally's sign convention; the ERP
            // records the invoice value as the positive figure a person reads.
            return number_format(abs((float) $amount), 4, '.', '');
        }

        return null;
    }

    private function date(\SimpleXMLElement $voucher): string
    {
        $raw = trim((string) ($this->tag($voucher, 'DATE') ?? ''));

        return Carbon::createFromFormat('Ymd', $raw)->toDateString();
    }

    private function tag(\SimpleXMLElement $voucher, string $tag): ?string
    {
        // Direct children only. The same tag names recur deep inside
        // EWAYBILLDETAILS and INVOICEORDERLIST, and a descendant search would
        // pick up an e-way bill's copy of the reference instead of the
        // voucher's own.
        $found = $voucher->xpath('./'.$tag);

        if (! $found) {
            return null;
        }

        $value = trim((string) $found[0]);

        return $value === '' ? null : $value;
    }
}
