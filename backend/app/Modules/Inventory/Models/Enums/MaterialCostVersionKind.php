<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * Why a lot's cost was recorded at this rate.
 *
 * Receipt is written by the system only — at lot creation, or by the
 * backfill migration. The other three are what Finance may append when the
 * paperwork behind a provisional GRN rate finally lands.
 */
enum MaterialCostVersionKind: string
{
    /** The original goods-receipt rate. Never edited, never re-created. */
    case Receipt = 'receipt';

    /** The supplier's purchase invoice corrected the receipt rate. */
    case Invoice = 'invoice';

    /** Freight, duty, insurance and the rest, loaded onto the lot rate. */
    case LandedCost = 'landed_cost';

    /** A recorded human correction — a keying error, a wrong lot. */
    case Correction = 'correction';

    /**
     * The kinds a person is allowed to append through the API. 'receipt' is
     * absent on purpose: the original rate is the system's to state, and
     * allowing it here would let a second "original" be filed after the fact.
     *
     * @return array<int, string>
     */
    public static function appendableValues(): array
    {
        return [self::Invoice->value, self::LandedCost->value, self::Correction->value];
    }
}
