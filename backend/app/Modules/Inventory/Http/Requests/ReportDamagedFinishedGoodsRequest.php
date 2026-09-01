<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reporting finished goods as damaged (DEC-20260901-006).
 *
 * The FINISHED-GOOD check and the standing-quantity bound are deliberately
 * NOT here. Both need the item and the balance under the same lock the move
 * takes, so they live in DamagedFinishedGoodService where the refusal and the
 * write cannot disagree. This class is shape only.
 */
class ReportDamagedFinishedGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            // PlainDecimal for the same reason every other quantity here has
            // it: `numeric` alone lets 1e400 and INF through to bcmath, which
            // is a 500 rather than a 422.
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999999', new PlainDecimal],
        ];
    }
}
