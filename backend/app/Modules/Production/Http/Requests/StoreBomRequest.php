<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('items', 'id')->where('is_active', true),
            ],
            'lines.*.quantity_per' => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $itemId = $this->input('item_id');

            foreach ($this->input('lines', []) as $index => $line) {
                if (($line['component_item_id'] ?? null) === $itemId) {
                    $validator->errors()->add(
                        "lines.{$index}.component_item_id",
                        'An item cannot be a component of its own BOM.',
                    );
                }
            }
        });
    }
}
