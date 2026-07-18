<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Exceptions\GstComputationException;
use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Sales\Models\Invoice;

/**
 * Reads Sales' Invoice (and its Item/Customer relations) directly — a read
 * dependency, not a write, same rule Quality's IncomingInspectionService
 * follows for Procurement's GRN line. Nothing here mutates Invoice; the
 * breakdown is computed fresh on every call, never persisted, so rate
 * changes or corrected HSN codes are reflected immediately rather than
 * needing a re-save of historical invoices.
 */
class GstComputationService
{
    /**
     * @return array{seller_gstin: string, seller_state_code: string, customer_gstin: ?string, customer_state_code: string, supply_type: string, lines: array<int, array{item_id: int, hsn_sac_code: string, taxable_value: string, rate_percent: string, cgst: string, sgst: string, igst: string, total: string}>, totals: array{taxable_value: string, cgst: string, sgst: string, igst: string, total_tax: string, grand_total: string}}
     */
    public function invoiceBreakdown(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines.item', 'customer']);

        $seller = GstRegistration::query()->where('is_primary', true)->where('is_active', true)->first();
        if (! $seller) {
            throw GstComputationException::noPrimaryRegistration();
        }

        $customer = $invoice->customer;
        if (empty($customer->state_code)) {
            throw GstComputationException::customerStateUnknown($customer->id);
        }

        $isInterState = $customer->state_code !== $seller->state_code;

        $lines = [];
        $totalTaxable = '0.0000';
        $totalCgst = '0.0000';
        $totalSgst = '0.0000';
        $totalIgst = '0.0000';

        foreach ($invoice->lines as $line) {
            $item = $line->item;

            if (empty($item->hsn_sac_code)) {
                throw GstComputationException::missingHsnCode($item->id);
            }

            $rate = GstRate::query()->where('hsn_sac_code', $item->hsn_sac_code)->where('is_active', true)->first();
            if (! $rate) {
                throw GstComputationException::missingRate($item->id, $item->hsn_sac_code);
            }

            $taxableValue = bcmul($line->quantity, $line->unit_price, 4);
            $taxAmount = bcmul($taxableValue, bcdiv((string) $rate->rate_percent, '100', 6), 4);

            if ($isInterState) {
                $cgst = '0.0000';
                $sgst = '0.0000';
                $igst = $taxAmount;
            } else {
                $half = bcdiv($taxAmount, '2', 4);
                $cgst = $half;
                $sgst = $half;
                $igst = '0.0000';
            }

            $lines[] = [
                'item_id' => $item->id,
                'hsn_sac_code' => $item->hsn_sac_code,
                'taxable_value' => $taxableValue,
                'rate_percent' => (string) $rate->rate_percent,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total' => bcadd($taxableValue, bcadd($cgst, bcadd($sgst, $igst, 4), 4), 4),
            ];

            $totalTaxable = bcadd($totalTaxable, $taxableValue, 4);
            $totalCgst = bcadd($totalCgst, $cgst, 4);
            $totalSgst = bcadd($totalSgst, $sgst, 4);
            $totalIgst = bcadd($totalIgst, $igst, 4);
        }

        $totalTax = bcadd($totalCgst, bcadd($totalSgst, $totalIgst, 4), 4);

        return [
            'seller_gstin' => $seller->gstin,
            'seller_state_code' => $seller->state_code,
            'customer_gstin' => $customer->gstin,
            'customer_state_code' => $customer->state_code,
            'supply_type' => $isInterState ? 'inter_state' : 'intra_state',
            'lines' => $lines,
            'totals' => [
                'taxable_value' => $totalTaxable,
                'cgst' => $totalCgst,
                'sgst' => $totalSgst,
                'igst' => $totalIgst,
                'total_tax' => $totalTax,
                'grand_total' => bcadd($totalTaxable, $totalTax, 4),
            ],
        ];
    }
}
