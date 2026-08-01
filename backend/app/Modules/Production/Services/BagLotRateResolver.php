<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\MaterialLot;

/**
 * THE ONE PLACE PRODUCTION ASKS "WHAT DID THIS BAG COST", and the seam that
 * lets the bag-cost layer exist before Inventory's rate columns do.
 *
 * THE CONTRACT IT ADAPTS (Inventory's, landing in parallel):
 *
 *     MaterialLot::currentRatePerKg(): ?string   the rate in force NOW —
 *         the receipt rate, or a later audited cost version if a purchase
 *         invoice or landed-cost adjustment has revised it
 *     MaterialLot::receiptRatePerKg(): ?string   the ORIGINAL GRN rate,
 *         preserved forever
 *
 * WHY AN ADAPTER RATHER THAN A DIRECT CALL. Two reasons, and the second is
 * the one that matters.
 *
 * First, the contract is not merged yet. Calling it directly would make
 * this whole module unloadable until it is; guarding with method_exists at
 * every call site would scatter the same conditional through the
 * allocation loop. Here it is asked once, in one class, and every caller
 * gets a plain answer.
 *
 * Second — and this is why the adapter stays after the contract lands —
 * DECIDING BETWEEN 'bag_receipt' AND 'bag_version' IS A JUDGEMENT, not a
 * lookup, and it belongs somewhere with a name. A rate that still equals
 * the original GRN rate is a receipt rate; one that has moved is a revised
 * cost version, and an accountant reading a batch's cost is entitled to
 * know which of the two they are looking at, because only the second means
 * "this number changed after the material was bought".
 *
 * IT NEVER INVENTS A PRICE. No contract, no lot, a null rate, or a rate of
 * zero all answer the same way: [null, 'unknown']. Zero is not a price —
 * it is the ABSENCE of one, the same rule
 * ShiftProductionEntryService::materialCost() applies to a zero unit_cost
 * stamped by an issue that ran a bin negative. An opening-stock lot has no
 * cost source at all (material_lots.goods_receipt_note_line_id is
 * nullable), and saying so honestly is the entire point.
 */
class BagLotRateResolver
{
    public const SOURCE_BAG_RECEIPT = 'bag_receipt';

    public const SOURCE_BAG_VERSION = 'bag_version';

    public const SOURCE_AVERAGE_FALLBACK = 'average_fallback';

    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * The rate to price a slice of this lot's material at, and where that
     * rate came from.
     *
     * @return array{0: ?string, 1: string} [rate_per_kg, rate_source]
     */
    public function rateFor(?MaterialLot $lot): array
    {
        if ($lot === null || ! method_exists($lot, 'currentRatePerKg')) {
            return [null, self::SOURCE_UNKNOWN];
        }

        $current = $lot->currentRatePerKg();

        if ($current === null || ! is_numeric($current) || bccomp((string) $current, '0', 4) !== 1) {
            return [null, self::SOURCE_UNKNOWN];
        }

        $current = bcadd((string) $current, '0', 4);

        return [$current, $this->sourceFor($lot, $current)];
    }

    /**
     * Receipt rate, or a revised cost version?
     *
     * hasRevisions() IS THE AUTHORITATIVE ANSWER and is preferred whenever
     * Inventory offers it, because comparing the two rates gets one real
     * case wrong: a lot that arrived with NO receipt rate and whose first
     * ever version is a purchase invoice HAS been revised, and there is no
     * original to compare it against. A reader deciding whether to trust a
     * number needs to be told that, and only the owning module knows it.
     *
     * The rate comparison is the fallback for the same reason the whole
     * class exists — so this keeps working against a contract that has not
     * finished landing.
     */
    private function sourceFor(object $lot, string $current): string
    {
        if (method_exists($lot, 'hasRevisions')) {
            return $lot->hasRevisions() ? self::SOURCE_BAG_VERSION : self::SOURCE_BAG_RECEIPT;
        }

        $receipt = method_exists($lot, 'receiptRatePerKg') ? $lot->receiptRatePerKg() : null;

        return ($receipt !== null && is_numeric($receipt) && bccomp($current, bcadd((string) $receipt, '0', 4), 4) !== 0)
            ? self::SOURCE_BAG_VERSION
            : self::SOURCE_BAG_RECEIPT;
    }
}
