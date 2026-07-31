<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a standard by hand, for a product the factory workbook never carried.
 *
 * Stricter than the importer on the four figures that drive every expected
 * output — cavities, weight, cycle time — and that is the point of the
 * difference: the importer must accept a blank and flag it, because refusing a
 * workbook row would silently lose a product the factory actually runs. A
 * person filling in a form is being asked for those numbers directly and can
 * simply be told which one is missing.
 *
 * The packing counts are optional and use the importer's own field names, so
 * ProductionStandardImportService::packagings() derives the rows from them
 * unchanged — pouch, tray, or straight into the box.
 */
class StoreProductionStandardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_product_name' => ['required', 'string', 'max:255'],
            'cavities' => ['required', 'integer', 'min:1', 'max:512'],
            'unit_weight_grams' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'cycle_time' => ['required', 'numeric', 'gt:0', 'max:999999'],

            // Optional: a standard with no item yet is the normal starting
            // state on this page, and the attach endpoint finishes the job.
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],

            // Reference text, same three columns the workbook carries.
            'carton_spec' => ['nullable', 'string', 'max:64'],
            'tray_spec' => ['nullable', 'string', 'max:64'],
            'pouch_spec' => ['nullable', 'string', 'max:64'],

            // Packing counts. Names match the importer's row keys exactly.
            'nos_per_pouch' => ['nullable', 'integer', 'min:1'],
            'pouches_per_box' => ['nullable', 'integer', 'min:1'],
            'pouch_nos_per_box' => ['nullable', 'integer', 'min:1'],
            'nos_per_tray' => ['nullable', 'integer', 'min:1'],
            'trays_per_box' => ['nullable', 'integer', 'min:1'],
            'tray_nos_per_box' => ['nullable', 'integer', 'min:1'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'cavities.min' => 'A mould has at least one cavity.',
            'unit_weight_grams.gt' => 'A bottle weighs more than nothing — enter the unit weight in grams.',
            'cycle_time.gt' => 'Enter the cycle time in seconds; every expected-output figure is derived from it.',
        ];
    }
}
