<?php

namespace App\Modules\Procurement\Services;

use App\Modules\TallySync\Models\Ledger;

/**
 * WHAT A TALLY LEDGER SAYS A VENDOR IS — the mapping, in one place, used by
 * everything that turns a ledger into vendor fields.
 *
 * It exists because there were about to be two of them. `ImportVendorsFromLedgers`
 * has its own field mapping, has been reviewed and run against the live
 * master, and the review screen must not quietly grow a second set of rules
 * for the same act — that is how two rival systems for one thing begin. Both
 * callers read this class instead, so a change to what a vendor inherits from
 * Tally happens once and reaches both.
 *
 * IT INVENTS NOTHING. A field Tally does not carry comes back null, never a
 * fabricated placeholder (AGENTS.md). The state code is READ from the GSTIN
 * rather than from the ledger's own state field, because that is measurably
 * the better source: of 620 Sundry Creditors only 22 carry a state at all,
 * while 307 carry a GSTIN, and a GSTIN's first two digits ARE the GST state
 * code by the format's definition.
 */
final class VendorFromLedger
{
    /**
     * The vendor fields this ledger proposes, in the order a person reads
     * them. Keys are vendor column names; a null value means "Tally has
     * nothing here".
     *
     * @return array<string, string|null>
     */
    public static function attributes(Ledger $ledger): array
    {
        $gstin = self::clean($ledger->gstin);

        return [
            'name' => self::clean($ledger->name),
            'email' => self::clean($ledger->email),
            'phone' => self::clean($ledger->phone),
            'gstin' => $gstin,
            'state_code' => self::stateCodeFrom((string) $gstin),
            // The ledger's own name IS what a voucher must call this party, so
            // the mapping Accounts would otherwise type in is recorded from
            // the source it comes from.
            'tally_ledger_name' => self::clean($ledger->name),
        ];
    }

    /**
     * The GST state code a GSTIN carries in its first two digits.
     *
     * Not an inference: a GSTIN is defined as the two-digit state code, then
     * the PAN, then the entity and check characters. Read only from a value
     * that is the right length and starts with two digits, so a malformed or
     * absent GSTIN yields null rather than a made-up code.
     */
    public static function stateCodeFrom(string $gstin): ?string
    {
        return strlen($gstin) === 15 && ctype_digit(substr($gstin, 0, 2))
            ? substr($gstin, 0, 2)
            : null;
    }

    /** Trimmed, with the empty string collapsed to null — blank is not a value. */
    public static function clean(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
