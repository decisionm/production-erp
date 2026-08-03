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
     *
     * A stated mould or colour SELECTS a qualified configuration; it does not
     * hide the general one. That distinction was a real bug: an equality
     * filter (`where('colour', $colour)`) excluded the colour-null
     * configuration entirely, so a machine holding one general approved
     * standard resolved to NOTHING the moment a run stated its colour — while
     * the batch preview, which resolves without a colour, found that same
     * standard and quoted its figures. Preview and Start Batch could
     * therefore disagree about whether a product was configured at all, and
     * the run silently fell back to the item master.
     *
     * It also contradicted the ordering three lines below, which exists
     * precisely to rank a colour-qualified row ABOVE a general one — an
     * ordering that can only ever matter if both rows are in the result set.
     * So the filter is a match-or-general one, and the specific-first
     * ordering picks the winner: the Amber configuration when there is one,
     * the general configuration when there is not.
     */
    public function resolve(int $workCenterId, int $itemId, ?int $moldId = null, ?string $colour = null, ?string $on = null): ?ProductionConfiguration
    {
        $date = $on ?? now()->toDateString();

        return ProductionConfiguration::query()
            ->with(['item', 'mold', 'bom.lines.component'])
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('status', ConfigurationStatus::Approved->value)
            ->when($moldId !== null, fn ($q) => $q->where(
                fn ($sub) => $sub->where('mold_id', $moldId)->orWhereNull('mold_id'),
            ))
            ->when($colour !== null, fn ($q) => $q->where(
                fn ($sub) => $sub->where('colour', $colour)->orWhereNull('colour'),
            ))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            // Most specific first: a mould/colour-qualified configuration
            // beats a general one for the same machine and product.
            ->orderByRaw('CASE WHEN mold_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN colour IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_from')
            // THE NEWEST APPROVED REVISION WINS, explicitly.
            //
            // Without this last clause two approved configurations that tie on
            // mould, colour and effective_from left the winner to whatever the
            // storage engine returned first. That is exactly what happened to
            // 60 ml Round Amber: configurations #62 (4 cavities) and #63 (5)
            // were both approved on MC-04 at the same instant with no
            // effective dates, all three clauses tied, and the run silently
            // took #62's 4 against a Product Standard of 5. The choice was a
            // row accident, and it would have flipped on an index rebuild.
            //
            // approved_at first because that is the revision's own date;
            // id last so the ordering is total and can never tie again.
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Approved configurations that overlap: same product, same machine, same
     * mould, same colour, with effective windows that both cover some date.
     *
     * A REPORT, NOT A REFUSAL, and deliberately so. The overlaps already exist
     * — six groups on the live instance, four of them contradicting each other
     * on cavities — so a validation rule that started rejecting saves would
     * block work over rows somebody has to look at anyway. Naming them is the
     * thing that was missing: the resolver picked one silently, and no screen
     * ever said there had been a choice.
     *
     * `values_differ` separates the two cases that matter. Two overlapping rows
     * carrying identical figures are untidy; two carrying 4 and 5 cavities are
     * a wrong expected-output figure on every run of that product.
     *
     * @return list<array{item_id: int, work_center_id: int, configuration_ids: list<int>, values_differ: bool}>
     */
    public function overlappingApproved(): array
    {
        $approved = ProductionConfiguration::query()
            ->where('status', ConfigurationStatus::Approved->value)
            ->get(['id', 'item_id', 'work_center_id', 'mold_id', 'colour', 'effective_from', 'effective_to', 'default_cavities', 'default_cycle_time']);

        $groups = [];

        foreach ($approved as $configuration) {
            $key = implode('|', [
                $configuration->item_id,
                $configuration->work_center_id,
                $configuration->mold_id ?? '-',
                $configuration->colour ?? '-',
            ]);
            $groups[$key][] = $configuration;
        }

        $out = [];

        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            // Only pairs whose windows actually meet. Two rows that took over
            // from one another in sequence are correct history, not a clash.
            $clashing = [];

            foreach ($rows as $i => $a) {
                foreach ($rows as $j => $b) {
                    if ($j <= $i || ! $this->windowsOverlap($a, $b)) {
                        continue;
                    }
                    $clashing[$a->id] = $a;
                    $clashing[$b->id] = $b;
                }
            }

            if ($clashing === []) {
                continue;
            }

            $values = array_unique(array_map(
                fn (ProductionConfiguration $c) => $c->default_cavities.'|'.$c->default_cycle_time,
                $clashing,
            ));

            $first = reset($clashing);

            $out[] = [
                'item_id' => (int) $first->item_id,
                'work_center_id' => (int) $first->work_center_id,
                'configuration_ids' => array_values(array_map(fn ($c) => (int) $c->id, $clashing)),
                'values_differ' => count($values) > 1,
            ];
        }

        return $out;
    }

    /** Do two effective windows cover any common date? Null is open-ended. */
    private function windowsOverlap(ProductionConfiguration $a, ProductionConfiguration $b): bool
    {
        $aFrom = $a->effective_from;
        $aTo = $a->effective_to;
        $bFrom = $b->effective_from;
        $bTo = $b->effective_to;

        // a starts after b ends, or b starts after a ends → no overlap.
        if ($aFrom !== null && $bTo !== null && $aFrom > $bTo) {
            return false;
        }

        if ($bFrom !== null && $aTo !== null && $bFrom > $aTo) {
            return false;
        }

        return true;
    }

    /**
     * Every configuration for one product, across all machines — the machine
     * exceptions the Product Standards workspace expands under a row.
     *
     * @return Collection<int, ProductionConfiguration>
     */
    public function forItem(int $itemId): Collection
    {
        return $this->forItems([$itemId]);
    }

    /**
     * The same list for many products at once — what the workspace needs to
     * show exceptions on a page of fifty products without a query per row.
     *
     * ONE method rather than two because the ordering is the point. These
     * rows appear twice on the same screen: inside the workspace row, and
     * again when the expanded row fetches them from
     * standards/{standard}/machine-exceptions. Two implementations would
     * order them two ways — MC-10 above MC-02 in one and below it in the
     * other — and the list would appear to reshuffle when it was opened.
     *
     * The order is the machine list's own (WorkCenterService::ordered):
     * display_sequence, then the digits in the code, so MC-02 sorts before
     * MC-10 and the exceptions read in the same sequence as the machines
     * page. Callers name the relations they need — the workspace wants two
     * and the endpoint wants five, and loading five for a page of fifty is
     * three queries nobody reads.
     *
     * Every status, deliberately: a draft awaiting approval and a retired
     * standard are both part of the answer to "what is different about this
     * product on some machine?", and hiding the draft is how a configuration
     * sits unapproved for a month without anyone seeing it.
     *
     * @param  list<int>  $itemIds
     * @param  list<string>  $with
     * @return Collection<int, ProductionConfiguration>
     */
    public function forItems(array $itemIds, array $with = ['workCenter', 'item', 'mold', 'bom', 'approvedBy']): Collection
    {
        if ($itemIds === []) {
            return ProductionConfiguration::query()->whereRaw('1 = 0')->get();
        }

        return ProductionConfiguration::query()
            ->with($with)
            ->whereIn('production_configurations.item_id', $itemIds)
            ->join('work_centers', 'work_centers.id', '=', 'production_configurations.work_center_id')
            ->orderBy('production_configurations.item_id')
            ->orderByRaw('work_centers.display_sequence IS NULL')
            ->orderBy('work_centers.display_sequence')
            ->orderByRaw('LENGTH(work_centers.code)')
            ->orderBy('work_centers.code')
            ->orderBy('production_configurations.id')
            ->select('production_configurations.*')
            ->get();
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

        // Cycle-time bounds are ADVISORY, never blocking. The factory's own
        // product master contains 48 approved standards above the 14 s
        // "global maximum" — up to 30.5 s — so a blocking bound would
        // refuse half the real catalogue. Unusual values are surfaced as
        // warnings by cycleTimeWarning(); they never stop a valid standard.
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
        // $itemFallback is either an Item (standard_cycle_time /
        // standard_cavities) or a ProductionStandard (cycle_time /
        // cavities). Both are read here so the caller can pass whichever is
        // the best available source without a second code path.
        $fallbackCt = $itemFallback?->cycle_time ?? $itemFallback?->standard_cycle_time ?? null;
        $fallbackCav = $itemFallback?->cavities ?? $itemFallback?->standard_cavities ?? null;

        $baseCt = $configuration?->default_cycle_time !== null
            ? (string) $configuration->default_cycle_time
            : ($fallbackCt !== null ? (string) $fallbackCt : null);
        $baseCav = $configuration?->default_cavities ?? $fallbackCav;

        $ctSource = $configuration?->default_cycle_time !== null ? 'configuration' : ($baseCt !== null ? 'product_standard' : 'none');
        $cavSource = $configuration?->default_cavities !== null ? 'configuration' : ($baseCav !== null ? 'product_standard' : 'none');

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

    /**
     * An advisory note when a cycle time sits outside the declared bounds,
     * or null when it does not. Deliberately not an exception: the factory's
     * master carries valid standards well outside the global range, and a
     * supervisor who cannot start a real product because of a config row
     * will simply stop using the app.
     */
    public function cycleTimeWarning(?string $value, ?ProductionConfiguration $configuration = null, ?object $machine = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $min = $configuration?->cycle_time_min ?? $machine?->cycle_time_min;
        $max = $configuration?->cycle_time_max ?? $machine?->cycle_time_max;

        if ($min !== null && (float) $value < (float) $min) {
            return "Cycle time {$value}s is below the usual minimum of {$min}s for this machine.";
        }
        if ($max !== null && (float) $value > (float) $max) {
            return "Cycle time {$value}s is above the usual maximum of {$max}s for this machine.";
        }

        return null;
    }

    private function assertCycleTimeAllowed(string $value, ?ProductionConfiguration $configuration): void
    {
        // Intentionally empty of throws — see cycleTimeWarning(). Kept as a
        // named seam so the call site still reads as "check the cycle time",
        // and so a future factory decision to make bounds hard has one
        // obvious place to live.
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
