<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMaterialLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bagCount = (int) $this->input('bag_count', 0);

        return [
            'grn_id' => ['nullable', 'required_with:goods_receipt_note_line_id', 'integer', 'exists:goods_receipt_notes,id'],
            'goods_receipt_note_line_id' => ['nullable', 'required_with:grn_id', 'integer', 'exists:goods_receipt_note_lines,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'received_date' => ['required', 'date'],
            'bag_count' => ['required', 'integer', 'min:1', 'max:2000'],
            'bag_weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'total_received_kg' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'required_with:grn_id', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            // Supplier barcodes (Vincent Q1): when the bags carry scannable
            // codes, one per bag; omitted = app-generated LOT{lot}-B{seq}.
            // distinct + unique give the friendly 422 — the material_bags
            // UNIQUE column is the hard guard underneath either way.
            'barcodes' => ['nullable', 'array', 'size:'.$bagCount],
            'barcodes.*' => ['required', 'string', 'max:64', 'distinct', Rule::unique('material_bags', 'barcode')],
            // Individually weighed bags; omitted = nominal bag_weight_kg,
            // else total/count.
            'bag_weights' => ['nullable', 'array', 'size:'.$bagCount],
            'bag_weights.*' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $bagCount = $this->integer('bag_count');
            $bagWeights = array_values($this->input('bag_weights', []));
            $bagTotal = null;

            if ($bagWeights !== []) {
                $bagTotal = array_reduce(
                    $bagWeights,
                    fn (string $total, mixed $weight) => bcadd($total, (string) $weight, 4),
                    '0.0000',
                );
            } elseif ($this->input('bag_weight_kg') !== null) {
                $bagTotal = bcmul((string) $this->input('bag_weight_kg'), (string) $bagCount, 4);
            }

            if ($bagTotal !== null && bccomp($bagTotal, (string) $this->input('total_received_kg'), 4) !== 0) {
                $validator->errors()->add(
                    'total_received_kg',
                    "Lot total must equal the physical bag total of {$bagTotal} kg.",
                );
            }

            if ($this->input('grn_id') === null) {
                return;
            }

            $line = GoodsReceiptNoteLine::query()
                ->with('goodsReceiptNote')
                ->find($this->integer('goods_receipt_note_line_id'));

            if ($line === null || $line->goods_receipt_note_id !== $this->integer('grn_id')) {
                $validator->errors()->add(
                    'goods_receipt_note_line_id',
                    'This receipt line does not belong to the selected GRN.',
                );

                return;
            }

            if ($line->item_id !== $this->integer('item_id')) {
                $validator->errors()->add('item_id', 'The material item must match the selected GRN line.');
            }

            $warehouseId = $this->input('warehouse_id');
            if ($warehouseId !== null && $line->goodsReceiptNote?->warehouse_id !== (int) $warehouseId) {
                $validator->errors()->add('warehouse_id', 'The bag warehouse must match the GRN warehouse.');
            }

            $alreadyRegistered = MaterialLot::query()
                ->where('goods_receipt_note_line_id', $line->id)
                ->get()
                ->reduce(
                    fn (string $total, MaterialLot $lot) => bcadd($total, (string) $lot->total_received_kg, 4),
                    '0.0000',
                );
            $afterThisLot = bcadd($alreadyRegistered, (string) $this->input('total_received_kg'), 4);

            if (bccomp($afterThisLot, (string) $line->quantity, 4) === 1) {
                $validator->errors()->add(
                    'total_received_kg',
                    'Registered lot quantities cannot exceed the selected GRN line quantity.',
                );
            }
        });
    }
}
