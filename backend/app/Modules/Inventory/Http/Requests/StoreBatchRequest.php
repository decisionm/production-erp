<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * A BATCH IS ONLY A BATCH ON A BATCH-TRACKED ITEM, and only on one
             * still in service. `exists:items,id` alone let a lot be opened
             * against a serial-tracked part, against an item nobody tracks at
             * all — where it became a batch nothing could ever be issued
             * against — and against an ARCHIVED item, which the WS-B rule
             * (audit 17-Aug-2026 §1) already refuses on every stock path.
             *
             * `whereNull('deleted_at')` is not redundant with `is_active`.
             * `Rule::exists` queries the table directly and so does NOT apply
             * the SoftDeletes scope, and deleting an item does not clear the
             * flag — a soft-deleted item can sit there `is_active = true` and
             * take a brand-new lot. StoreStoreIssueRequest spells the same
             * clause out for the same reason.
             *
             * Batches ALREADY recorded are untouched and still read back, the
             * same way a movement naming a retired master still renders.
             */
            'item_id' => [
                'required', 'integer',
                Rule::exists('items', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('tracking_type', ItemTrackingType::Batch->value),
            ],
            'batch_number' => [
                'required', 'string', 'max:64',
                Rule::unique('batches')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'manufactured_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:manufactured_date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
