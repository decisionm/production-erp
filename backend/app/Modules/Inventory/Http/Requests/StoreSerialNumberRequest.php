<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSerialNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The same rule as StoreBatchRequest, the other way round: a
            // serial number only exists on a serial-tracked item still in
            // service. Registering one against anything else minted an
            // identity no stock movement would ever be allowed to carry.
            // `deleted_at` is spelled out because `Rule::exists` skips the
            // SoftDeletes scope and deleting an item leaves `is_active` alone.
            'item_id' => [
                'required', 'integer',
                Rule::exists('items', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('tracking_type', ItemTrackingType::Serial->value),
            ],
            'serial_number' => [
                'required', 'string', 'max:64',
                Rule::unique('serial_numbers')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
        ];
    }
}
