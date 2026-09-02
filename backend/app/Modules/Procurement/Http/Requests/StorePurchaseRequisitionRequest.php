<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Inventory\Services\ItemService;
use App\Modules\Procurement\Support\PurchaseLineEligibility;
use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'needed_by_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.notes' => ['nullable', 'string'],
            'lines.*.unclassified_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * DEC-20260902-023: a finished good is refused, and an unclassified item
     * needs a reason. See PurchaseLineEligibility's class docblock for why
     * this reads categories through ItemService rather than the Item model.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = (array) $this->input('lines', []);
            $ids = array_values(array_unique(array_filter(array_map(
                fn ($line) => isset($line['item_id']) ? (int) $line['item_id'] : null,
                $lines,
            ))));

            PurchaseLineEligibility::validate(
                $lines,
                fn (string $key, string $message) => $validator->errors()->add($key, $message),
                app(ItemService::class)->categoriesFor($ids),
            );
        });
    }
}
