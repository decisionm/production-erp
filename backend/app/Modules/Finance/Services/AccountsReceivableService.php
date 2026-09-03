<?php

namespace App\Modules\Finance\Services;

use App\Modules\Sales\Services\InvoiceService;

/**
 * There is no separate Finance-owned receivables table — Sales' Invoice
 * already has the right shape (customer, status, due date) to be the AR
 * subledger, so this just reads it via Sales' own Service, never touching
 * its models directly. Deliberately no Accounts Payable counterpart yet:
 * Procurement has no vendor-bill document with its own paid/unpaid status
 * to source one from (a GoodsReceiptNote records stock received, not a
 * billing obligation) — add one there first if AP is needed.
 */
class AccountsReceivableService
{
    // WHAT THIS FIGURE STANDS ON is InvoiceService::BASIS, deliberately not
    // a second constant here: the ERP's own sales invoice is retired
    // (DEC-20260903-004), that is a fact about the SALES document, and
    // Compliance's GSTR-1 has to print the same words. One source, one
    // sentence.

    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * @return array<int, array{invoice_id: int, customer: array{id: int, code: string, name: string}, invoice_date: string, due_date: ?string, status: string, amount: string}>
     */
    public function outstanding(): array
    {
        return $this->invoices->unpaid()->map(function ($invoice) {
            $amount = $invoice->lines->reduce(
                fn (string $carry, $line) => bcadd($carry, bcmul($line->quantity, $line->unit_price, 4), 4),
                '0.0000',
            );

            return [
                'invoice_id' => $invoice->id,
                'customer' => [
                    'id' => $invoice->customer->id,
                    'code' => $invoice->customer->code,
                    'name' => $invoice->customer->name,
                ],
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status->value,
                'amount' => $amount,
            ];
        })->values()->all();
    }

    public function outstandingTotal(): string
    {
        return array_reduce(
            $this->outstanding(),
            fn (string $carry, array $row) => bcadd($carry, $row['amount'], 4),
            '0.0000',
        );
    }
}
