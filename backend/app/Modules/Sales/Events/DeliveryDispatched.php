<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Delivery;

/**
 * Raised when finished goods are dispatched to a customer. Sales announces the
 * fact; TallySync listens and enqueues a Tally Delivery Note voucher. Sales
 * stays unaware TallySync exists.
 */
class DeliveryDispatched
{
    public function __construct(public readonly Delivery $delivery) {}
}
