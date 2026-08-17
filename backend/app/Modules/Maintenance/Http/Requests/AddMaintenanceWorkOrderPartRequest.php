<?php

namespace App\Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddMaintenanceWorkOrderPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B: drawing a spare is a stock issue, so it obeys the same
            // active-item / active-store rule as the stock paths.
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
