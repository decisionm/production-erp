<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Names the warehouses the floor is never asked about — the finished-goods
 * destination and the raw-material source.
 *
 * Same settings shape as `PUT settings/day-bin-warehouse`: named values in,
 * the stored values echoed back. Both keys are optional so one role can be
 * set without disturbing the other; sending a key with `null` CLEARS that
 * role, which is a real operation — it returns that role to the
 * single-Tally-linked-warehouse fallback rather than leaving it pointed at
 * something stale.
 *
 * ACTIVE IS REQUIRED HERE, unlike the day-bin request. FactoryWarehouseResolver
 * reads a setting that names an inactive warehouse as "not set" and falls
 * through, so accepting one would store a value that silently never takes
 * effect — the setting screen would show it configured while every payload
 * resolved somewhere else. Refusing it up front is the difference between a
 * clear "pick an active warehouse" and a setting that lies.
 */
class UpdateFactoryWarehousesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by module:production (manage for a PUT)
    }

    public function rules(): array
    {
        return [
            'finished_goods_warehouse_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('is_active', true),
            ],
            'raw_material_warehouse_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('is_active', true),
            ],
        ];
    }
}
