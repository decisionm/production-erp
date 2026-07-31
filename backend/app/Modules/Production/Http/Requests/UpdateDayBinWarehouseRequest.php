<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDayBinWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by module:production (manage for a PUT)
    }

    /**
     * `null` is a legitimate value — it clears the setting and returns the
     * floor to pre-day-bin behaviour. A warehouse WITHOUT a Tally godown
     * identity is accepted too: it makes the consumption voucher's godown
     * unresolvable, which the screen warns about, but refusing it here would
     * leave a factory unable to configure the bin at all.
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['present', 'nullable', 'integer', 'exists:warehouses,id'],
        ];
    }
}
