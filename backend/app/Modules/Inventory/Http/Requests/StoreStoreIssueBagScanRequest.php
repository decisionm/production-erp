<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One bag scanned at the handover.
 *
 * quantity_kg is OPTIONAL and means "this much was weighed off"; absent
 * means the whole bag. There is NO machine and NO area field, and that is
 * FC-01 with DEC-20260807-006 behind it: resin enters through one common
 * piped loading point, so a resin scan cannot name a machine. (A consumable
 * request — film, cartons, tape — does carry a work centre, and it carries
 * it on the REQUEST, where the ask was made.)
 */
class StoreStoreIssueBagScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:255'],
            'quantity_kg' => ['nullable', 'numeric'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            'material_request_line_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
