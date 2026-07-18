<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Exceptions\GstComputationException;
use App\Modules\Sales\Services\InvoiceService;

/**
 * Real GSTR-1-shaped output built entirely from issued Sales Invoices —
 * B2B (customer has a GSTIN) vs B2C (doesn't), each with a per-invoice
 * taxable value/CGST/SGST/IGST breakdown plus grand totals. Not yet
 * period-filtered (a real return is per month/quarter) — this covers all
 * issued invoices to date; date-range filtering is a natural next step,
 * not built here to keep this pass proportionate.
 *
 * One invoice with incomplete master data (no HSN code on an item, no
 * state code on the customer, ...) must not make the whole return
 * unusable — a real business will always have a few of these at any
 * given time. Such invoices are skipped and reported back in `errors`
 * instead of raising, so the return reflects everything that *is*
 * computable while flagging exactly what needs fixing before filing.
 */
class GstReportService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly GstComputationService $computation,
    ) {}

    public function gstr1(): array
    {
        $b2b = [];
        $b2c = [];
        $errors = [];
        $totals = ['taxable_value' => '0.0000', 'cgst' => '0.0000', 'sgst' => '0.0000', 'igst' => '0.0000', 'total_tax' => '0.0000'];

        foreach ($this->invoices->issued() as $invoice) {
            try {
                $breakdown = $this->computation->invoiceBreakdown($invoice);
            } catch (GstComputationException $e) {
                $errors[] = ['invoice_id' => $invoice->id, 'message' => $e->getMessage()];

                continue;
            }

            $row = [
                'invoice_id' => $invoice->id,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'customer_name' => $invoice->customer->name,
                'customer_gstin' => $breakdown['customer_gstin'],
                'supply_type' => $breakdown['supply_type'],
                'taxable_value' => $breakdown['totals']['taxable_value'],
                'cgst' => $breakdown['totals']['cgst'],
                'sgst' => $breakdown['totals']['sgst'],
                'igst' => $breakdown['totals']['igst'],
                'total_tax' => $breakdown['totals']['total_tax'],
            ];

            if ($breakdown['customer_gstin']) {
                $b2b[] = $row;
            } else {
                $b2c[] = $row;
            }

            foreach (['taxable_value', 'cgst', 'sgst', 'igst', 'total_tax'] as $key) {
                $totals[$key] = bcadd($totals[$key], $breakdown['totals'][$key], 4);
            }
        }

        return [
            'b2b' => $b2b,
            'b2c' => $b2c,
            'errors' => $errors,
            'totals' => $totals,
        ];
    }
}
