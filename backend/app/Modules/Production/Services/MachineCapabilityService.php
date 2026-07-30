<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\WorkCenter;

/**
 * Which machines a standard's cavity count allows.
 *
 * The factory's rule: at or above a cavity threshold (6), the mould is only
 * mounted on certain machines (Machine 10); below it, any machine will do.
 * Configured in `production.machine_capability` — see the rationale there for
 * why this is a rule read off the standard rather than a stored mapping table.
 *
 * One service so the Start Batch preview's warning and the record written
 * against the started batch cannot disagree. A screen that warned about a
 * restriction the stored snapshot did not mention (or the reverse) would leave
 * nobody able to say afterwards what the app actually thought at the time.
 */
class MachineCapabilityService
{
    public const WARNING_CODE = 'machine_cavity_restricted';

    /** @return list<int> */
    public function restrictedWorkCenterIds(): array
    {
        /** @var list<int> $ids */
        $ids = config('production.machine_capability.high_cavity_work_center_ids', []);

        return $ids;
    }

    public function threshold(): int
    {
        return (int) config('production.machine_capability.cavity_threshold', 6);
    }

    public function isEnforced(): bool
    {
        return (bool) config('production.machine_capability.enforced', false);
    }

    /**
     * Does this cavity count fall under the restriction at all?
     *
     * A null cavity count does NOT: the sheet leaves cavities blank on rows
     * that already carry an unresolved flag, and treating "unknown" as "high"
     * would restrict a product on a figure nobody has.
     */
    public function isRestricted(?int $cavities): bool
    {
        return $cavities !== null && $cavities >= $this->threshold() && $this->restrictedWorkCenterIds() !== [];
    }

    public function allows(?int $cavities, ?int $workCenterId): bool
    {
        if (! $this->isRestricted($cavities)) {
            return true;
        }

        // No machine named yet (the supervisor has not picked one) is not a
        // violation — there is nothing to be wrong about.
        if ($workCenterId === null) {
            return true;
        }

        return in_array($workCenterId, $this->restrictedWorkCenterIds(), true);
    }

    /**
     * The advisory note for an intended run, or null when the rule is satisfied
     * or does not apply.
     *
     * Names the machines the mould IS for, rather than only saying no: a
     * supervisor who has to go and find out which machine to use will start it
     * here anyway.
     *
     * @return array{code: string, message: string}|null
     */
    public function warningFor(?ProductionStandard $standard, ?int $workCenterId): ?array
    {
        $cavities = $standard?->cavities;

        if ($this->allows($cavities, $workCenterId)) {
            return null;
        }

        $names = WorkCenter::query()
            ->whereIn('id', $this->restrictedWorkCenterIds())
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $allowed = $names === []
            // The configured ids do not exist in the work-centre master.
            // Reported as ids rather than silently dropped: a rule pointing at
            // nothing is a configuration error someone needs to see.
            ? 'work centre '.implode(', ', array_map('strval', $this->restrictedWorkCenterIds()))
            : implode(' or ', $names);

        $running = WorkCenter::query()->find($workCenterId)?->name ?? 'this machine';

        return [
            'code' => self::WARNING_CODE,
            'message' => sprintf(
                'This standard runs %d cavities, and moulds of %d or more are set up for %s — you are starting it on %s. The batch will record that.',
                (int) $cavities,
                $this->threshold(),
                $allowed,
                $running,
            ),
        ];
    }
}
