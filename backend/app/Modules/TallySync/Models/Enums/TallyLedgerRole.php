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
