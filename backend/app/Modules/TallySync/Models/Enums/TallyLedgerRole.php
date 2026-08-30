<?php

namespace App\Modules\TallySync\Models\Enums;

/**
 * The posting roles a voucher line can play. Each is mapped to a real Tally
 * ledger NAME per client via TallyLedgerMapping (config, not code) so the
 * voucher builders never hardcode a ledger. Add a case here when a new voucher
 * type needs a new ledger role.
 */
enum TallyLedgerRole: string
{
    case Sales = 'sales';
    // THE TWO SALES LEDGERS THE FACTORY ACTUALLY POSTS THROUGH. Measured, not
    // assumed: across the 100 inventory lines of the 30-Aug reading of the real
    // Tally Sales export there are exactly two, 'Interstate Sales Taxable' (79
    // lines) and 'Local Sales Taxable' (21), chosen by the same buyer-state vs
    // company-state test that decides IGST vs CGST+SGST — 54 of 54 live
    // vouchers conform with no exceptions.
    //
    // DELIBERATELY NOT THE FOUR-LEDGER PURCHASE SHAPE. DEC-20260812-003 keys
    // purchases (local|interstate) x rate because Tally really uses four
    // purchase ledgers. Sales in that export is single-rate (18.00% on all 54),
    // so a rate axis would be inventing cells no evidence supports. If a second
    // sales rate ever appears, add the cases then — the mapping is config, and
    // widening it is a one-line change here plus a row in the table.
    case SalesLocal = 'sales_local';
    case SalesInterstate = 'sales_interstate';
    // ONE global ledger for every Purchase Order line, and TESTING-ONLY: the
    // factory's real Tally actually posts through FOUR purchase ledgers
    // (local × interstate × rate — DEC-20260812-003), and which rate/ledger
    // applies is still open (Q39). This mapping backs the OFF-by-default
    // tally-sync.purchase_orders_enabled staging gate — it is never the
    // production ledger scheme and must not be widened into one on a guess.
    case Purchase = 'purchase';
    case Cgst = 'cgst';
    case Sgst = 'sgst';
    case Igst = 'igst';
    case RoundOff = 'round_off';
    // Production-side, for the Manufacturing Journal (Phase 4).
    case ResinConsumption = 'resin_consumption';
    case RegrindCredit = 'regrind_credit';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::SalesLocal => 'Sales — local (CGST + SGST)',
            self::SalesInterstate => 'Sales — interstate (IGST)',
            self::Purchase => 'Purchase',
            self::Cgst => 'CGST (output)',
            self::Sgst => 'SGST (output)',
            self::Igst => 'IGST (output)',
            self::RoundOff => 'Round Off',
            self::ResinConsumption => 'Resin / RM Consumption',
            self::RegrindCredit => 'Regrind Credit',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
