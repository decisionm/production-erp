<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ONLY two fields of a sales order this endpoint will take: the
 * customer's promise date and the desk's notes. Everything else an order
 * carries — its customer, its status, its order date, its lines — is
 * deliberately absent here, so a body naming them changes nothing
 * (validated() cannot return a key this list does not name, and
 * SalesOrderService::update() copies the two keys across explicitly rather
 * than mass-assigning what it was handed).
 *
 * `expected_date` is the date the ORDER promises, owned by hand by whoever
 * types it. It is not a computed production ETA and nothing derives it.
 *
 * `sometimes` on both: an ABSENT key leaves the stored value alone, an
 * explicit `null` clears it. Two different requests, two different
 * outcomes — the service judges on the key being present, never on the
 * value being truthy.
 */
class UpdateSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];

        // Compared against the STORED order date, never against a field of
        // this request: order_date is not editable here, so the floor for
        // the promise is whatever the order was raised with.
        $order = $this->route('sales_order');
        if ($order instanceof SalesOrder && $order->order_date !== null) {
            $rules['expected_date'][] = 'after_or_equal:'.$order->order_date->toDateString();
        }

        return $rules;
    }
}
