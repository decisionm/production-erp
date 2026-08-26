<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /inventory/fulfilment/lines/{line}/reserve — hold this many pieces
 * for this order line.
 *
 * PlainDecimal, not `numeric` alone: `numeric` takes `1e+21` — which is what
 * `JSON.stringify` emits for any JavaScript number at or above 1e21, so an
 * antd InputNumber with a key held down reaches the exponent spelling
 * without anybody typing an `e` — and every figure here goes straight into
 * bcmath, which throws on it. The rule is App\Rules\PlainDecimal for the
 * reason its docblock gives: this predicate is written down ONCE.
 *
 * NOTHING ELSE IS VALIDATED HERE. Whether the order is live, whether the
 * stock is free, and whether the line still owes the customer that much are
 * all facts that can change between this validation and the write, so
 * StockReservationService recomputes every one of them inside its
 * transaction under the balance lock and refuses with the real figures.
 */
class ReserveStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.gt' => 'Hold a quantity greater than zero.',
        ];
    }
}
