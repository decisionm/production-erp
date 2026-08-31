<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\TallyPurchaseRate;

/**
 * WHAT THIS VENDOR LAST CHARGED FOR THIS ITEM, according to Tally.
 *
 * Answers the purchase-order form's question: the latest Purchase Order rate,
 * the latest Purchase invoice rate, and — only when it is safe — one of them
 * as a suggestion to prefill. Read-only, and every figure it returns is
 * attributed to the voucher it came from and the sync that read it.
 *
 * THE UNIT RULE IS THE WHOLE SAFETY STORY OF THIS CLASS. Tally quotes a rate
 * as `674.000/Kgs.` — a number AND the basis it is per. Q40 records 28 of 382
 * purchase-order lines carrying two units, trays and covers bought by weight
 * and counted in pieces. Prefilling a bare number onto an ERP line whose unit
 * is the other one silently restates the price of a real order, and nothing
 * on the screen would show it. So:
 *
 *   - a rate whose unit MATCHES the item's own unit may be suggested;
 *   - a rate whose unit DIFFERS is still SHOWN, with both units, and
 *     explicitly refuses to prefill, saying why;
 *   - a rate with no unit at all is shown and does not prefill either,
 *     because "no basis recorded" is not the same as "the same basis".
 *
 * WHICH ONE LEADS. The latest by voucher date across both kinds — an invoice
 * from July outranks an order from April, and the suggestion always names
 * which kind it came from so nobody mistakes an agreed rate for a billed one.
 * When both carry the same date the INVOICE leads: it is what was actually
 * paid. Purchase rates are Owner/Accounts only (FC-06) — this class does not
 * enforce that, its callers do, and they must.
 *
 * WHAT IT DOES NOT DO: it holds no per-item tax rate and reads no `gst_rates`
 * row. The GST it reports is what that voucher carried on its date, because
 * Q39 measured that the rate is a property of neither the item nor the vendor.
 */
class PurchaseRateLookup
{
    /**
     * @return array{
     *     vendor: array{id: int, name: string, tally_ledger_name: string|null}|null,
     *     item: array{id: int, name: string, uom: string|null}|null,
     *     purchase_order: array<string, mixed>|null,
     *     purchase_invoice: array<string, mixed>|null,
     *     suggestion: array<string, mixed>|null,
     *     unavailable_reason: string|null,
     *     last_synced_at: string|null
     * }
     */
    public function forVendorAndItem(Vendor $vendor, Item $item): array
    {
        $party = $this->partyLedgerNameFor($vendor);

        $header = [
            'vendor' => ['id' => $vendor->id, 'name' => $vendor->name, 'tally_ledger_name' => $vendor->tally_ledger_name],
            'item' => ['id' => $item->id, 'name' => $item->name, 'uom' => $item->uom],
        ];

        if ($party === null) {
            // Not an error and not an empty result — a specific, fixable
            // state. Nothing in Tally can be found for a vendor that has not
            // been told which party it is.
            return [
                ...$header,
                'purchase_order' => null,
                'purchase_invoice' => null,
                'suggestion' => null,
                'unavailable_reason' => 'This vendor is not linked to a Tally ledger, so Tally has no rate to look up. Link it on the Tally vendor review.',
                'last_synced_at' => null,
            ];
        }

        $order = $this->latest($party, $item, TallyPurchaseRate::TYPE_PURCHASE_ORDER);
        $invoice = $this->latest($party, $item, TallyPurchaseRate::TYPE_PURCHASE_INVOICE);

        if ($order === null && $invoice === null) {
            return [
                ...$header,
                'purchase_order' => null,
                'purchase_invoice' => null,
                'suggestion' => null,
                'unavailable_reason' => 'No Tally purchase order or purchase invoice has been synced for this vendor and item.',
                'last_synced_at' => null,
            ];
        }

        $leader = $this->leaderOf($order, $invoice);

        return [
            ...$header,
            'purchase_order' => $order !== null ? $this->present($order, $item) : null,
            'purchase_invoice' => $invoice !== null ? $this->present($invoice, $item) : null,
            'suggestion' => $leader !== null ? $this->present($leader, $item) : null,
            'unavailable_reason' => null,
            // The newer of the two stamps, spelled out rather than left to
            // max()'s null handling — one of them is routinely null.
            'last_synced_at' => collect([$order?->tally_synced_at, $invoice?->tally_synced_at])
                ->filter()->max()?->toIso8601String(),
        ];
    }

    /**
     * The Tally party a vendor is.
     *
     * The GUID link is exact and preferred; the typed ledger name is the
     * fallback that has existed on the vendor form since Phase 6. Both resolve
     * to the NAME, because that is what a Day Book voucher carries — there is
     * no party GUID on one.
     */
    private function partyLedgerNameFor(Vendor $vendor): ?string
    {
        if ($vendor->tally_ledger_guid !== null) {
            $name = Ledger::where('tally_guid', $vendor->tally_ledger_guid)->value('name');

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        $typed = trim((string) ($vendor->tally_ledger_name ?? ''));

        return $typed !== '' ? $typed : null;
    }

    /** The newest line of one kind for this party and item, or null. */
    private function latest(string $party, Item $item, string $type): ?TallyPurchaseRate
    {
        return TallyPurchaseRate::query()
            ->where('voucher_type', $type)
            ->where('party_ledger_name', $party)
            ->where(function ($query) use ($item): void {
                // Matched by Tally identity when the item has one, and by the
                // stock item's name otherwise. Never by the ERP's own SKU or
                // display name — those are the ERP's words for the thing, and
                // Tally has never heard them.
                if ($item->tally_stock_item_guid !== null) {
                    $query->where('tally_stock_item_guid', $item->tally_stock_item_guid);
                } else {
                    $query->where('stock_item_name', $item->name);
                }
            })
            // Date first, then id: two vouchers on one day are separated by
            // the order they were read, which is the only further ordering
            // the data supports.
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The one that leads — latest by voucher date; the invoice on a tie,
     * because it is what was actually paid.
     */
    private function leaderOf(?TallyPurchaseRate $order, ?TallyPurchaseRate $invoice): ?TallyPurchaseRate
    {
        if ($order === null) {
            return $invoice;
        }
        if ($invoice === null) {
            return $order;
        }

        return $invoice->voucher_date >= $order->voucher_date ? $invoice : $order;
    }

    /** @return array<string, mixed> */
    private function present(TallyPurchaseRate $rate, Item $item): array
    {
        $unitMatches = self::sameUnit($rate->rate_unit, $item->uom);
        $hasUnit = trim((string) ($rate->rate_unit ?? '')) !== '';

        return [
            'voucher_type' => $rate->voucher_type,
            'voucher_number' => $rate->voucher_number,
            'voucher_reference' => $rate->voucher_reference,
            'voucher_date' => optional($rate->voucher_date)?->toDateString(),
            'party_ledger_name' => $rate->party_ledger_name,
            'party_gstin' => $rate->party_gstin,
            'stock_item_name' => $rate->stock_item_name,
            'rate_value' => $rate->rate_value,
            'rate_unit' => $rate->rate_unit,
            'quantity' => $rate->quantity,
            'quantity_unit' => $rate->quantity_unit,
            'amount' => $rate->amount,
            'gst' => [
                'cgst_rate' => $rate->cgst_rate,
                'sgst_rate' => $rate->sgst_rate,
                'igst_rate' => $rate->igst_rate,
                'cess_rate' => $rate->cess_rate,
                'hsn_code' => $rate->hsn_code,
                'purchase_ledger_name' => $rate->purchase_ledger_name,
            ],
            'item_uom' => $item->uom,
            'unit_matches' => $unitMatches,
            // THE FIELD THE FORM OBEYS. Everything above is information a
            // person may read and act on; this is the only thing that may
            // move a number into an editable price field by itself.
            'may_prefill' => $unitMatches,
            'prefill_blocked_reason' => $unitMatches ? null : ($hasUnit
                ? sprintf('Tally quotes this rate per %s and the item is held in %s. Confirm the basis before using it.', $rate->rate_unit, $item->uom ?? '(no unit)')
                : 'Tally recorded no unit for this rate, so the basis cannot be confirmed.'),
            'source' => 'tally',
            'tally_synced_at' => optional($rate->tally_synced_at)?->toIso8601String(),
        ];
    }

    /**
     * The same unit, spelled either way.
     *
     * Case-folded, trimmed, and a trailing dot removed — `Kgs.` and `Kgs` are
     * Tally's own two spellings of one unit and are the same basis. Nothing
     * further: `Kgs` and `Nos` are not reconciled, and neither are `kg` and
     * `Kgs` — two units are the same here only if they are the same word.
     * Netting one unit off another is the exact mistake FC-03 exists about,
     * and a rate is a price per unit, so it is the same mistake with money.
     */
    public static function sameUnit(?string $a, ?string $b): bool
    {
        $normalise = fn (?string $v) => rtrim(mb_strtolower(trim((string) $v)), '.');

        $left = $normalise($a);
        $right = $normalise($b);

        // Two unknowns are not a match. "No unit recorded" on both sides tells
        // us nothing about whether the basis agrees.
        return $left !== '' && $left === $right;
    }
}
