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

    /**
     * The machine's OWN stored capability verdict, or null when the machine
     * declares nothing.
     *
     * The Machines & Capabilities tab edits work_centers.permitted_cavities and
     * min/max_cavities, and those columns were already consulted when SAVING a
     * configuration — but not by this service, so the Start Batch warning ran
     * off the .env list while the screen where people maintain capabilities did
     * nothing. Two sources of the same truth, one of them dead. The machine's
     * own declaration now wins wherever it exists, which makes that tab the
     * live control: edit a machine's cavity range and the floor's warnings
     * follow, with no deploy and no .env edit.
     *
     * An explicit permitted list beats the min/max range, matching the
     * precedence configuration saves already use.
     */
    private function machineVerdict(WorkCenter $machine, int $cavities): ?bool
    {
        $permitted = $machine->permitted_cavities;
        if (is_array($permitted) && $permitted !== []) {
            return in_array($cavities, array_map('intval', $permitted), true);
        }

        if ($machine->min_cavities === null && $machine->max_cavities === null) {
            return null;
        }

        if ($machine->min_cavities !== null && $cavities < $machine->min_cavities) {
            return false;
        }

        return ! ($machine->max_cavities !== null && $cavities > $machine->max_cavities);
    }

    public function allows(?int $cavities, ?int $workCenterId): bool
    {
        // No machine named yet (the supervisor has not picked one) is not a
        // violation — there is nothing to be wrong about. Same for an unknown
        // cavity count: restricting a product on a figure nobody has would
        // invent a rule the factory never stated.
        if ($cavities === null || $workCenterId === null) {
            return true;
        }

        $machine = WorkCenter::query()->find($workCenterId);

        if ($machine !== null) {
            $verdict = $this->machineVerdict($machine, $cavities);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        // The machine declares nothing about itself — fall back to the global
        // threshold rule from config.
        if (! $this->isRestricted($cavities)) {
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

        $machine = WorkCenter::query()->find($workCenterId);
        $running = $machine?->name ?? 'this machine';

        // When the MACHINE'S OWN capability refused, say what the machine is
        // set up for — that is the figure someone maintains on the Machines &
        // Capabilities tab, and the message should point back at it.
        if ($machine !== null && $this->machineVerdict($machine, (int) $cavities) === false) {
            $permitted = $machine->permitted_cavities;
            $setup = is_array($permitted) && $permitted !== []
                ? 'cavities '.implode(', ', array_map('intval', $permitted))
                : trim(sprintf(
                    '%s%s cavities',
                    $machine->min_cavities !== null ? $machine->min_cavities.'–' : 'up to ',
                    $machine->max_cavities ?? '',
                ));

            return [
                'code' => self::WARNING_CODE,
                'message' => sprintf(
                    'This standard runs %d cavities, but %s is set up for %s (Machines & Capabilities). The batch will record that.',
                    (int) $cavities,
                    $running,
                    $setup,
                ),
            ];
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
