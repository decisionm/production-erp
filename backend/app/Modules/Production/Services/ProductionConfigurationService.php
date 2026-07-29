<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The machine-product configuration lifecycle: draft → approved → inactive,
 * and the resolution that Start Batch depends on.
 *
 * Two rules carry the safety of the whole feature:
 *
 *  1. Only an APPROVED configuration, effective today, may drive a batch.
 *     Draft rows exist to be reviewed and are invisible to production.
 *  2. Two approved configurations for the same (machine, item, mould,
 *     colour) may never overlap in time. Otherwise "the" standard for a run
 *     is ambiguous, and an ambiguous standard silently picks one — which is
 *     how a factory ends up measuring against a rate nobody agreed to.
 */
class ProductionConfigurationService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return ProductionConfiguration::query()
            ->with(['workCenter', 'item', 'mold', 'bom', 'approvedBy'])
            ->when($filters['work_center_id'] ?? null, fn ($q, $v) => $q->where('work_center_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('item_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas(
                'item',
                fn ($iq) => $iq->where('name', 'like', "%{$v}%")->orWhere('sku', 'like', "%{$v}%")
            ))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Every approved configuration a machine may run today — what the Start
     * Batch product picker is filtered to.
     *
     * @return Collection<int, ProductionConfiguration>
     */
    public function approvedForMachine(int $workCenterId, ?string $on = null): Collection
    {
        $date = $on ?? now()->toDateString();

        return ProductionConfiguration::query()
            ->with(['item', 'mold', 'bom'])
            ->where('work_center_id', $workCenterId)
            ->where('status', ConfigurationStatus::Approved->value)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderBy('item_id')
            ->get();
    }

    /**
     * The single approved configuration governing one intended run, or null
     * when the product has none — in which case the caller falls back to the
     * item master and MUST label that as legacy/unconfigured.
     */
    public function resolve(int $workCenterId, int $itemId, ?int $moldId = null, ?string $colour = null, ?string $on = null): ?ProductionConfiguration
    {
        $date = $on ?? now()->toDateString();

        return ProductionConfiguration::query()
            ->with(['item', 'mold', 'bom.lines.component'])
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('status', ConfigurationStatus::Approved->value)
            ->when($moldId !== null, fn ($q) => $q->where('mold_id', $moldId))
            ->when($colour !== null, fn ($q) => $q->where('colour', $colour))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            // Most specific first: a mould/colour-qualified configuration
            // beats a general one for the same machine and product.
            ->orderByRaw('CASE WHEN mold_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN colour IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $createdBy): ProductionConfiguration
    {
        // Always born a draft. Approval is a separate, attributable act —
        // see approve().
        return ProductionConfiguration::create([
            ...$data,
            'status' => ConfigurationStatus::Draft->value,
            'created_by' => $createdBy,
        ])->load(['workCenter', 'item', 'mold', 'bom']);
    }

    /** @param array<string, mixed> $data */
    public function update(ProductionConfiguration $configuration, array $data): ProductionConfiguration
    {
        // An approved configuration is what live batches resolve against;
        // editing its numbers under them would retroactively change the
        // standard a running shift is being measured on. Clone-and-approve
        // is the supported path.
        if ($configuration->status === ConfigurationStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'An approved configuration cannot be edited. Copy it to a new draft, or set it inactive first.',
            ]);
        }

        $configuration->update($data);

        return $configuration->fresh(['workCenter', 'item', 'mold', 'bom']);
    }

    /**
     * Approve a draft. Validates against the machine's own capabilities and
     * refuses to create an overlapping active standard.
     */
    public function approve(ProductionConfiguration $configuration, ?int $approvedBy): ProductionConfiguration
    {
        if ($configuration->status !== ConfigurationStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'production configuration',
                $configuration->status->value,
                ConfigurationStatus::Approved->value,
            );
        }

        return DB::transaction(function () use ($configuration, $approvedBy) {
            $this->assertComplete($configuration);
            $this->assertWithinMachineCapability($configuration);
            $this->assertNoOverlap($configuration);

            $configuration->update([
                'status' => ConfigurationStatus::Approved->value,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'effective_from' => $configuration->effective_from ?? now()->toDateString(),
            ]);

            return $configuration->fresh(['workCenter', 'item', 'mold', 'bom', 'approvedBy']);
        });
    }

    public function deactivate(ProductionConfiguration $configuration): ProductionConfiguration
    {
        $configuration->update([
            'status' => ConfigurationStatus::Inactive->value,
            'effective_to' => $configuration->effective_to ?? now()->toDateString(),
        ]);

        return $configuration->fresh(['workCenter', 'item', 'mold', 'bom']);
    }

    /** Copy an existing configuration into a fresh draft (clone-to-revise). */
    public function copy(ProductionConfiguration $configuration, ?int $createdBy): ProductionConfiguration
    {
        $clone = $configuration->replicate([
            'status', 'approved_by', 'approved_at', 'effective_from', 'effective_to', 'created_by',
        ]);
        $clone->status = ConfigurationStatus::Draft->value;
        $clone->created_by = $createdBy;
        $clone->source = 'copy';
        $clone->source_reference = (string) $configuration->id;
        $clone->save();

        return $clone->fresh(['workCenter', 'item', 'mold', 'bom']);
    }

    /**
     * A configuration must carry the values a batch cannot run without. This
     * is the gate that stops a "To Confirm" import row being approved with
     * its blanks intact.
     */
    private function assertComplete(ProductionConfiguration $configuration): void
    {
        $missing = [];

        if ($configuration->default_cycle_time === null || (float) $configuration->default_cycle_time <= 0) {
            $missing[] = 'default cycle time';
        }
        if ($configuration->default_cavities === null || $configuration->default_cavities <= 0) {
            $missing[] = 'default active cavities';
        }
        if ($configuration->unit_weight_grams === null || (float) $configuration->unit_weight_grams <= 0) {
            $missing[] = 'unit weight';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'status' => 'Cannot approve — still missing: '.implode(', ', $missing).'.',
            ]);
        }
    }

    /**
     * The machine's capability bounds win over the configuration's. A
     * configuration may narrow a machine's range; it may never widen it,
     * because the machine is the physical constraint.
     */
    private function assertWithinMachineCapability(ProductionConfiguration $configuration): void
    {
        $machine = $configuration->workCenter ?? WorkCenter::find($configuration->work_center_id);
        if ($machine === null) {
            return;
        }

        $cavities = $configuration->default_cavities;
        $permitted = $machine->permitted_cavities;

        if (is_array($permitted) && $permitted !== [] && ! in_array($cavities, array_map('intval', $permitted), true)) {
            throw ValidationException::withMessages([
                'default_cavities' => "{$machine->name} permits only these cavity options: ".implode(', ', $permitted).'.',
            ]);
        }

        if ($machine->min_cavities !== null && $cavities < $machine->min_cavities) {
            throw ValidationException::withMessages([
                'default_cavities' => "{$machine->name} has a minimum of {$machine->min_cavities} cavities.",
            ]);
        }
        if ($machine->max_cavities !== null && $cavities > $machine->max_cavities) {
            throw ValidationException::withMessages([
                'default_cavities' => "{$machine->name} has a maximum of {$machine->max_cavities} cavities.",
            ]);
        }

        $ct = (float) $configuration->default_cycle_time;
        if ($machine->cycle_time_min !== null && $ct < (float) $machine->cycle_time_min) {
            throw ValidationException::withMessages([
                'default_cycle_time' => "{$machine->name} has a minimum cycle time of {$machine->cycle_time_min}s.",
            ]);
        }
        if ($machine->cycle_time_max !== null && $ct > (float) $machine->cycle_time_max) {
            throw ValidationException::withMessages([
                'default_cycle_time' => "{$machine->name} has a maximum cycle time of {$machine->cycle_time_max}s.",
            ]);
        }
    }

    /**
     * No two approved configurations for the same effective key may be live
     * at once. Compared as date ranges with null meaning open-ended.
     */
    private function assertNoOverlap(ProductionConfiguration $configuration): void
    {
        $from = $configuration->effective_from?->toDateString() ?? now()->toDateString();
        $to = $configuration->effective_to?->toDateString();

        $clash = ProductionConfiguration::query()
            ->where('id', '!=', $configuration->id)
            ->where('work_center_id', $configuration->work_center_id)
            ->where('item_id', $configuration->item_id)
            ->where('status', ConfigurationStatus::Approved->value)
            ->when(
                $configuration->mold_id === null,
                fn ($q) => $q->whereNull('mold_id'),
                fn ($q) => $q->where('mold_id', $configuration->mold_id),
            )
            ->when(
                $configuration->colour === null,
                fn ($q) => $q->whereNull('colour'),
                fn ($q) => $q->where('colour', $configuration->colour),
            )
            // Ranges overlap unless one ends before the other starts.
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to ?? '9999-12-31'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->lockForUpdate()
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'effective_from' => "Configuration #{$clash->id} is already approved for this machine, product, mould and colour over an overlapping period. Set its end date first, or make this one start later.",
            ]);
        }
    }

    /**
     * Resolve the effective cycle time and cavities for a run, honouring a
     * bounded override. Every override carries a reason — an unexplained
     * deviation from the approved standard is exactly what the approval
     * screen exists to surface.
     *
     * @param  array{cycle_time?: ?string, cavities?: ?int, reason?: ?string}  $override
     * @return array{cycle_time: ?string, cavities: ?int, cycle_time_source: string, cavities_source: string, reason: ?string}
     */
    public function resolveEffectiveValues(?ProductionConfiguration $configuration, array $override, ?object $itemFallback = null): array
    {
        $baseCt = $configuration?->default_cycle_time !== null
            ? (string) $configuration->default_cycle_time
            : ($itemFallback?->standard_cycle_time !== null ? (string) $itemFallback->standard_cycle_time : null);
        $baseCav = $configuration?->default_cavities ?? $itemFallback?->standard_cavities;

        $ctSource = $configuration?->default_cycle_time !== null ? 'configuration' : ($baseCt !== null ? 'item_master' : 'none');
        $cavSource = $configuration?->default_cavities !== null ? 'configuration' : ($baseCav !== null ? 'item_master' : 'none');

        $ct = $baseCt;
        $cav = $baseCav;

        if (($override['cycle_time'] ?? null) !== null) {
            if ($configuration !== null) {
                $this->assertCycleTimeAllowed((string) $override['cycle_time'], $configuration);
            }
            $ct = (string) $override['cycle_time'];
            $ctSource = ($baseCt !== null && bccomp($baseCt, (string) $override['cycle_time'], 2) === 0) ? $ctSource : 'override';
        }

        if (($override['cavities'] ?? null) !== null) {
            if ($configuration !== null) {
                $this->assertCavitiesAllowed((int) $override['cavities'], $configuration);
            }
            $cav = (int) $override['cavities'];
            // Sending the default back is not an override — it is the form
            // echoing the prefill, and demanding a reason for it would train
            // supervisors to type noise.
            $cavSource = ($baseCav !== null && (int) $baseCav === $cav) ? $cavSource : 'override';
        }

        // A reason is demanded only when there IS an approved standard to
        // deviate from. An unconfigured product has no agreed cycle time or
        // cavity count, so requiring a "reason for overriding" would be
        // asking the supervisor to justify a deviation from nothing.
        $hasApprovedStandard = $configuration !== null;

        if ($hasApprovedStandard && ($ctSource === 'override' || $cavSource === 'override') && blank($override['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'override_reason' => 'A reason is required when overriding the approved cycle time or cavities.',
            ]);
        }

        return [
            'cycle_time' => $ct,
            'cavities' => $cav,
            'cycle_time_source' => $ctSource,
            'cavities_source' => $cavSource,
            'reason' => $override['reason'] ?? null,
        ];
    }

    private function assertCycleTimeAllowed(string $value, ?ProductionConfiguration $configuration): void
    {
        // Narrowest applicable bound wins: configuration, else machine, else
        // the global factory setting. An unbounded value is allowed only
        // when nothing anywhere declares a bound.
        $min = $configuration?->cycle_time_min ?? $configuration?->workCenter?->cycle_time_min;
        $max = $configuration?->cycle_time_max ?? $configuration?->workCenter?->cycle_time_max;

        if ($min !== null && (float) $value < (float) $min) {
            throw ValidationException::withMessages([
                'cycle_time' => "Cycle time {$value}s is below the permitted minimum of {$min}s.",
            ]);
        }
        if ($max !== null && (float) $value > (float) $max) {
            throw ValidationException::withMessages([
                'cycle_time' => "Cycle time {$value}s is above the permitted maximum of {$max}s.",
            ]);
        }
    }

    private function assertCavitiesAllowed(int $value, ?ProductionConfiguration $configuration): void
    {
        $permitted = $configuration?->permitted_cavities ?? $configuration?->workCenter?->permitted_cavities;

        if (is_array($permitted) && $permitted !== [] && ! in_array($value, array_map('intval', $permitted), true)) {
            throw ValidationException::withMessages([
                'cavities' => 'Permitted cavity options are: '.implode(', ', $permitted).'.',
            ]);
        }

        $min = $configuration?->cavities_min ?? $configuration?->workCenter?->min_cavities;
        $max = $configuration?->cavities_max ?? $configuration?->workCenter?->max_cavities;

        if ($min !== null && $value < $min) {
            throw ValidationException::withMessages(['cavities' => "Cavities {$value} is below the permitted minimum of {$min}."]);
        }
        if ($max !== null && $value > $max) {
            throw ValidationException::withMessages(['cavities' => "Cavities {$value} is above the permitted maximum of {$max}."]);
        }
    }
}
