<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Exceptions\MissingBasicComponentException;
use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalaryStructureService
{
    public function paginate(?int $employeeId, int $perPage = 20): LengthAwarePaginator
    {
        return SalaryStructure::query()
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->with(['employee', 'lines.component'])
            ->orderByDesc('effective_from')
            ->paginate($perPage);
    }

    /**
     * The structure in effect for an employee as of a given date — the most
     * recently effective one that isn't in the future.
     */
    public function currentFor(int $employeeId, string $asOfDate): ?SalaryStructure
    {
        return SalaryStructure::query()
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $asOfDate)
            ->with('lines.component')
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @param  array{employee_id: int, effective_from: string, lines: array<int, array{salary_component_id: int, amount?: string}>}  $data
     */
    public function create(array $data): SalaryStructure
    {
        return DB::transaction(function () use ($data) {
            $components = SalaryComponent::query()
                ->whereIn('id', collect($data['lines'])->pluck('salary_component_id'))
                ->get()
                ->keyBy('id');

            $basicAmount = $this->resolveBasicAmount($data['lines'], $components);

            $structure = SalaryStructure::create([
                'employee_id' => $data['employee_id'],
                'effective_from' => $data['effective_from'],
            ]);

            foreach ($data['lines'] as $line) {
                $component = $components->get($line['salary_component_id']);

                $amount = $component->calculation_type === SalaryCalculationType::PercentageOfBasic
                    ? $this->resolvePercentageOfBasic($component, $basicAmount)
                    : (string) ($line['amount'] ?? '0');

                $structure->lines()->create([
                    'salary_component_id' => $component->id,
                    'amount' => $amount,
                ]);
            }

            return $structure->load(['employee', 'lines.component']);
        });
    }

    private function resolveBasicAmount(array $lines, $components): ?string
    {
        foreach ($lines as $line) {
            $component = $components->get($line['salary_component_id']);

            if ($component && $component->code === 'BASIC') {
                return (string) ($line['amount'] ?? '0');
            }
        }

        return null;
    }

    private function resolvePercentageOfBasic(SalaryComponent $component, ?string $basicAmount): string
    {
        if ($basicAmount === null) {
            throw MissingBasicComponentException::forPercentageComponent($component->code);
        }

        return bcdiv(bcmul($basicAmount, (string) $component->percentage, 4), '100', 4);
    }
}
