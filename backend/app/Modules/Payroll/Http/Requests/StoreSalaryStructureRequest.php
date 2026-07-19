<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\SalaryComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.salary_component_id' => ['required', 'integer', 'distinct', 'exists:salary_components,id'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines', []);
            $componentIds = collect($lines)->pluck('salary_component_id')->filter();
            $components = SalaryComponent::query()->whereIn('id', $componentIds)->get()->keyBy('id');

            foreach ($lines as $index => $line) {
                $component = $components->get($line['salary_component_id'] ?? null);

                if ($component
                    && $component->calculation_type === SalaryCalculationType::FixedAmount
                    && ! isset($line['amount'])
                ) {
                    $validator->errors()->add(
                        "lines.{$index}.amount",
                        "The \"{$component->name}\" component is a fixed amount and requires an amount.",
                    );
                }
            }
        });
    }
}
