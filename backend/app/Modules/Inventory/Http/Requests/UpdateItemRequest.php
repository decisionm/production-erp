<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('items', 'sku')->ignore($item)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'uom' => ['sometimes', 'string', 'max:16'],
            'hsn_sac_code' => ['nullable', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'nominal_weight_grams' => ['nullable', 'numeric', 'gt:0'],
            'nos_per_tray' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'trays_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pouches_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:32'],
            'standard_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1'],
            'standard_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tracking_type' => ['sometimes', Rule::in(['none', 'batch', 'serial'])],
            'is_active' => ['boolean'],
            // The owner's switch for "the floor may request this". Nothing
            // infers it from a name, an SKU or a unit of measure.
            'is_production_input' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Item $item */
            $item = $this->route('item');

            // The NAME is the Tally wire key — every voucher line carries
            // <STOCKITEMNAME>, never the GUID — so renaming a Tally-linked item
            // here renames nothing in Tally; it makes the next voucher name an
            // item Tally does not have and fail days later as a rejection.
            // Tally owns the name; the masters pull brings a Tally-side rename
            // across. The same name resent unchanged is fine (forms echo it).
            if ($item->tally_stock_item_guid !== null
                && $this->has('name')
                && (string) $this->input('name') !== (string) $item->name) {
                $validator->errors()->add(
                    'name',
                    "\"{$item->name}\" is Tally's name for this item; rename it in Tally and pull masters, and the ERP follows.",
                );
            }

            // Item::isLocalFixture() treats a "LOCAL-" SKU as a fixture on the
            // prefix alone, so typing one onto a real item silently stops its
            // vouchers posting. Only an item that already IS a fixture may
            // carry the prefix — by EITHER signal, the same rule the model
            // applies: the import creates its fixtures on the prefix alone
            // (ProductionStandardImportService::localFixtureItem() never sets
            // the flag; the 13-Aug migration backfilled only what existed
            // then), and the Items form echoes the SKU back on every save, so
            // testing the raw column would lock every prefix-only fixture out
            // of its own edit modal.
            if ($this->has('sku')
                && str_starts_with((string) $this->input('sku'), Item::LOCAL_FIXTURE_SKU_PREFIX)
                && ! $item->isLocalFixture()) {
                $validator->errors()->add(
                    'sku',
                    'A SKU beginning "'.Item::LOCAL_FIXTURE_SKU_PREFIX.'" marks a local fixture that never posts to Tally — this item is not one; choose a SKU without that prefix.',
                );
            }
        });
    }
}
