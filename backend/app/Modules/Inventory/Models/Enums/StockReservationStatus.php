<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * The life of a stock hold.
 *
 *   active    the hold stands: some of it has neither left nor been given up
 *   consumed  it is spent — stock left on a delivery against it
 *   released  it was given up without any of it leaving
 *
 * MAINTAINED, NEVER CHOSEN. StockReservationService derives this from the
 * three quantities on every write: active while consumed+released <
 * quantity, else consumed when consumed_quantity > 0, else released. No
 * caller sets it and no screen may infer a different one.
 *
 * NOTE THE ASYMMETRY between `consumed` and Sales' delivered quantity: a
 * consumed hold means stock physically left on a Delivery, which is the ONE
 * event in this build that moves stock. Reserving, releasing and
 * re-pointing move nothing at all.
 */
enum StockReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Consumed = 'consumed';

    /** Still holding stock away from everyone else — the availability read's filter. */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /** Finished for good: nothing further happens to this hold. */
    public function isFinal(): bool
    {
        return $this === self::Released || $this === self::Consumed;
    }
}
