<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Compliance\Exceptions\GstComputationException;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Compliance\Services\GstComputationService;
use App\Modules\Compliance\Services\GstStateCodes;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;

/**
 * ASSEMBLES A GST-CORRECT TALLY 'Sales' VOUCHER PAYLOAD — or refuses, in words.
 *
 * WHY THIS CLASS EXISTS. The payload enqueueSalesInvoice() built before this
 * carried only {item, quantity, rate, amount} per line plus one ledger name.
 * The voucher the agent built from it had NO CGST, NO SGST, NO 'Rounding Off',
 * and debited the party the PRE-TAX total — a voucher that is not merely
 * tax-less but wrong by the tax. Against the factory's own Tally export (55
 * real Sales vouchers, read 30-Aug-2026) that is wrong in eight distinct ways.
 *
 * THE ONE INVARIANT EVERYTHING HERE SERVES. In all 54 live vouchers of that
 * export, without a single exception:
 *
 *     sum(line amounts) + tax + rounding + party = 0
 *
 * with the party carrying a NEGATIVE amount. `SalesVoucherPayloadTest` asserts
 * it on every payload this class builds, because a voucher that violates it is
 * one Tally may accept and an accountant must then unpick by hand.
 *
 * IT NEVER THROWS INTO THE DOMAIN ACT. Issuing an invoice is the factory's act
 * and must not fail because Tally staging could not be assembled — the same
 * rule the goods-receipt and purchase-order paths already follow. Every missing
 * ingredient becomes a REASON on the result, and the caller records it where a
 * person can read it. GstComputationService throws four ways (no primary
 * registration, unknown customer state, missing HSN, missing rate) and all four
 * are caught here.
 *
 * WHAT IT DELIBERATELY DOES NOT EMIT, each because the evidence says so:
 *   - REMOTEID / VCHKEY / GUID / ALTERID / MASTERID — Tally's own sync identity.
 *     Emitting one alongside ACTION="Create" risks ALTERING an existing voucher.
 *   - IRN / IRNQRCODE / e-way-bill numbers — these come back FROM the government
 *     portal after filing. Fabricating them claims a registration that does not exist.
 *   - BASICDUEDATEOFPYMT — Tally wants free text ('90 Days') and this ERP has no
 *     payment-terms field at all. Deriving it from (due_date - invoice_date) would
 *     be an invention; the tag is optional (present on 51 of 54) so it is omitted.
 */
final class SalesVoucherPayload
{
    public function __construct(
        private readonly GstComputationService $gst,
        private readonly TallyLedgerMappingService $ledgers,
        private readonly TallyGodownResolver $godowns,
    ) {}

    /**
     * @return array{payload: array<string, mixed>|null, reasons: list<array{code: string, detail: string}>}
     */
    public function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines.item', 'customer', 'salesOrder']);

        $reasons = [];

        // ---- 0. The invoice must have a real, persisted customer before the
        // tax is computed. THIS GUARD IS LOAD-BEARING, not defensive padding:
        // GstComputationException::customerStateUnknown() takes an `int
        // $customerId`, so an invoice whose customer has a null id raises a
        // TypeError rather than the named exception — and a TypeError is not a
        // GstComputationException, so it would escape the catch below and break
        // the promise this class makes in its own docblock, taking invoice
        // issuance down with it.
        $customer = $invoice->customer;
        if ($customer === null || $customer->getKey() === null) {
            return [
                'payload' => null,
                'reasons' => [[
                    'code' => 'customer_missing',
                    'detail' => 'The invoice has no persisted customer, so neither the party ledger nor the place of supply can be resolved.',
                ]],
            ];
        }

        // ---- 1. The tax. Four named refusals, none of them an exception here.
        $breakdown = null;
        try {
            $breakdown = $this->gst->invoiceBreakdown($invoice);
        } catch (GstComputationException $e) {
            $reasons[] = ['code' => 'gst_uncomputable', 'detail' => $e->getMessage()];
        }

        // ---- 2. The party, by its TALLY name — never customers.name, which is
        // the ERP's own label and matches Tally only by luck. tally_ledger_name
        // is written solely by sales:import-customers-from-ledgers.
        $partyLedger = $customer->tally_ledger_name;
        if (blank($partyLedger)) {
            $reasons[] = [
                'code' => 'customer_ledger_unmapped',
                'detail' => "Customer #{$customer?->id} has no tally_ledger_name — run sales:import-customers-from-ledgers. "
                    .'Posting against customers.name would name a ledger Tally may not have.',
            ];
        }

        // ---- 3. The states. The code decides the tax; the NAME is what the
        // voucher prints, and an unknown code is refused rather than guessed.
        $seller = GstRegistration::query()->where('is_primary', true)->where('is_active', true)->first();
        $sellerStateName = GstStateCodes::name($seller?->state_code);
        if ($seller !== null && $sellerStateName === null) {
            $reasons[] = [
                'code' => 'company_state_unknown',
                'detail' => "The company's GST registration carries state code '{$seller->state_code}', which is not a GST state code.",
            ];
        }

        $buyerStateName = GstStateCodes::name($customer?->state_code);
        if (! blank($customer?->state_code) && $buyerStateName === null) {
            $reasons[] = [
                'code' => 'customer_state_unknown',
                'detail' => "Customer #{$customer?->id} carries state code '{$customer?->state_code}', which is not a GST state code. "
                    .'PLACEOFSUPPLY cannot be named, and place of supply is what the whole local/interstate split turns on.',
            ];
        }

        // ---- 4. The godown. Invoices carry no warehouse, and this factory has
        // exactly one Tally-linked one — soleTallyGodownName() is that path and
        // returns null (never a guess) whenever the count is not exactly one.
        $godown = $this->godowns->soleTallyGodownName();
        if (blank($godown)) {
            $reasons[] = [
                'code' => 'godown_unresolved',
                'detail' => 'No single Tally-linked warehouse could be resolved for the godown. '
                    .'A Sales voucher names a godown on every line and one must not be guessed.',
            ];
        }

        // ---- 5. The ledgers. All of them config, none hardcoded — the old
        // builder's silent fallback to a literal 'Sales Account' is exactly the
        // failure this refuses.
        $isInterState = ($breakdown['supply_type'] ?? null) === 'inter_state';

        $salesRole = $isInterState ? TallyLedgerRole::SalesInterstate : TallyLedgerRole::SalesLocal;
        $salesLedger = $this->ledgers->get($salesRole);
        if (blank($salesLedger)) {
            $reasons[] = [
                'code' => 'sales_ledger_unmapped',
                'detail' => "No Tally ledger is mapped for the role '{$salesRole->value}' "
                    ."({$salesRole->label()}). Set it in Tally Sync → Ledger Mappings.",
            ];
        }

        $taxLedgers = [];
        if ($breakdown !== null) {
            // A LIST of [role, amount] pairs, not a role-keyed map: PHP enums
            // cannot be array keys. The ORDER is the order the voucher prints
            // them in — CGST before SGST, as the real vouchers do.
            $needed = $isInterState
                ? [[TallyLedgerRole::Igst, $breakdown['totals']['igst']]]
                : [[TallyLedgerRole::Cgst, $breakdown['totals']['cgst']], [TallyLedgerRole::Sgst, $breakdown['totals']['sgst']]];

            foreach ($needed as [$role, $amount]) {
                $name = $this->ledgers->get($role);
                if (blank($name)) {
                    $reasons[] = [
                        'code' => 'tax_ledger_unmapped',
                        'detail' => "No Tally ledger is mapped for the role '{$role->value}' ({$role->label()}).",
                    ];

                    continue;
                }
                $taxLedgers[] = ['ledger' => $name, 'amount' => $amount];
            }
        }

        // ---- 6. Rounding. The party total is the tax-inclusive total taken to
        // whole rupees, and 'Rounding Off' is the plug that gets it there. It is
        // OMITTED, never emitted as zero, when the total already lands whole —
        // 6 of the 54 real vouchers have no such entry.
        $roundOff = null;
        $partyAmount = null;
        if ($breakdown !== null) {
            $grand = $breakdown['totals']['grand_total'];
            $rounded = $this->toWholeRupees($grand);
            $difference = bcsub($rounded, $grand, 4);
            $partyAmount = $rounded;

            if (bccomp($difference, '0', 4) !== 0) {
                $roundOffLedger = $this->ledgers->get(TallyLedgerRole::RoundOff);
                if (blank($roundOffLedger)) {
                    $reasons[] = [
                        'code' => 'round_off_ledger_unmapped',
                        'detail' => "This invoice needs a rounding adjustment of {$difference} but no ledger is mapped "
                            ."for the role 'round_off'. The voucher would not balance.",
                    ];
                } else {
                    $roundOff = ['ledger' => $roundOffLedger, 'amount' => $difference];
                }
            }
        }

        if ($reasons !== []) {
            return ['payload' => null, 'reasons' => $reasons];
        }

        // ---- 7. The lines. Each carries its own accounting allocation, which
        // is the copy that participates in the balance.
        $lines = [];
        foreach ($invoice->lines as $index => $line) {
            $lineTax = $breakdown['lines'][$index] ?? null;
            $lines[] = [
                // The exact Tally stock-item name — items are pulled FROM Tally,
                // so item->name IS the Tally name. Never "sku - name".
                'item' => $line->item->name,
                'quantity' => (string) $line->quantity,
                'uom' => $line->item->uom,
                'rate' => (string) $line->unit_price,
                'amount' => $lineTax['taxable_value'] ?? bcmul((string) $line->quantity, (string) $line->unit_price, 4),
                'sales_ledger' => $salesLedger,
                'godown' => $godown,
            ];
        }

        return [
            'payload' => [
                'voucher_type' => 'Sales',
                'voucher_date' => $invoice->invoice_date?->toDateString(),
                // "INV-{id}". NOTE FOR ACCOUNTS: Tally owns its own Sales
                // series ('696/26-27' … 'Auto Renumber', contiguous), and the
                // factory's receipts knock off against THAT shape. Which book
                // mints an invoice number is an open Accounts question, not a
                // thing this builder may settle.
                'voucher_number' => $invoice->documentNumber(),
                'reference' => $invoice->salesOrder?->customer_po_reference,

                'party_ledger' => $partyLedger,
                'party_gstin' => $customer->gstin,
                'party_amount' => $partyAmount,

                'company_gstin' => $seller->gstin,
                'company_state' => $sellerStateName,
                // The BUYER's state. It is also the place of supply — the two are
                // equal in 55 of 55 real vouchers — and it is NOT the company's.
                'buyer_state' => $buyerStateName,
                'place_of_supply' => $buyerStateName,

                'supply_type' => $breakdown['supply_type'],
                'taxable_value' => $breakdown['totals']['taxable_value'],
                'tax_ledgers' => $taxLedgers,
                'round_off' => $roundOff,

                'godown' => $godown,
                'narration' => $invoice->notes,
                'lines' => $lines,
            ],
            'reasons' => [],
        ];
    }

    /**
     * Round half up, away from zero, to whole rupees — the rule measured on the
     * factory's own vouchers, where party = ROUND_HALF_UP(taxable + tax) held
     * with zero failures across all 48 vouchers carrying a rounding line.
     *
     * bcadd/bcsub at scale 0 TRUNCATE toward zero, so adding half first is what
     * turns truncation into half-up.
     */
    private function toWholeRupees(string $amount): string
    {
        return bccomp($amount, '0', 4) >= 0
            ? bcadd($amount, '0.5', 0)
            : bcsub($amount, '0.5', 0);
    }
}
