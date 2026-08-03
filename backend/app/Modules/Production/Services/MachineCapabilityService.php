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

    /**
     * The configured machine CODES, verbatim.
     *
     * @return list<string>
     */
    public function restrictedWorkCenterCodes(): array
    {
        /** @var list<string> $codes */
        $codes = config('production.machine_capability.high_cavity_work_center_codes', []);

        return $codes;
    }

    /**
     * The configured codes resolved to work-centre ids.
     *
     * Resolution is by CODE and never by id. The previous version of this
     * method read ids straight from config, and the configured `10` — written
     * meaning "Machine 10" — was MC-05, "Machine 5". Codes cannot be mistaken
     * for row numbers, which is the whole point of the change.
     *
     * A code matching no machine resolves to nothing. That is reported by
     * warningFor() rather than silently widening the rule to "any machine",
     * because a restriction that quietly stops restricting is the failure mode
     * this class exists to prevent.
     *
     * @return list<int>
     */
    public function restrictedWorkCenterIds(): array
    {
        $codes = $this->restrictedWorkCenterCodes();

        if ($codes === []) {
            return [];
        }

        return WorkCenter::query()
            ->whereIn('code', $codes)
            ->orderBy('display_sequence')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function threshold(): int
    {
        return (int) config('production.machine_capability.cavity_threshold', 6);
    }

    /**
     * The restricted machines as {id, name}, for screens that need to SAY the
     * rule rather than test one cavity count against it.
     *
     * The factory's rule — under the threshold runs anywhere, at or above it
     * runs on specific machines — was enforced everywhere and displayed
     * nowhere, so no screen could answer "which machines does this product run
     * on?". The owner asked for that mapping as data, and the honest answer is
     * that it is a rule, not a table: publishing it lets Product Standards
     * compute the answer per product without minting one row per
     * product-machine pair (roughly 790 of them) that would then drift from
     * the workbook the moment a cycle time was corrected.
     *
     * Ordered by the machine's own display sequence so the names read the way
     * the floor is walked.
     *
     * @return list<array{id: int, name: string}>
     */
    public function restrictedMachines(): array
    {
        $ids = $this->restrictedWorkCenterIds();

        if ($ids === []) {
            return [];
        }

        return WorkCenter::query()
            ->whereIn('id', $ids)
            ->orderBy('display_sequence')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (WorkCenter $machine) => ['id' => (int) $machine->id, 'name' => (string) $machine->name])
            ->all();
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

    /**
     * Does this run comply with the factory's machine recommendation?
     *
     * TWO SEPARATE QUESTIONS, and this is the first one:
     *
     *   compliesWithRecommendation() — is this the machine the rule names?
     *   isEnforced()                 — is a non-compliant run refused?
     *
     * They are deliberately not collapsed. While enforcement is off, a
     * non-compliant run PROCEEDS, and it must still be reported as
     * non-compliant and recorded as an exception — that exception log is the
     * evidence the factory will use to decide whether to switch enforcement
     * on. Answering "compliant" merely because nothing was refused would make
     * the log empty and the decision unmakeable.
     */
    public function compliesWithRecommendation(?int $cavities, ?int $workCenterId): bool
    {
        return $this->allows($cavities, $workCenterId);
    }

    /**
     * Whether the run is PERMITTED right now, which is compliance plus the
     * enforcement setting. With enforcement off every run is permitted,
     * compliant or not.
     */
    public function isPermitted(?int $cavities, ?int $workCenterId): bool
    {
        return ! $this->isEnforced() || $this->compliesWithRecommendation($cavities, $workCenterId);
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

        // AT OR ABOVE THE THRESHOLD THE FACTORY RULE IS FINAL, and it is
        // checked FIRST — ahead of the machine's own declaration.
        //
        // The order matters and used to be the other way round. With the
        // machine's own capability winning, a 5-cavity mould passed on any
        // machine whose permitted list happened to include 5, and the factory's
        // "five or more means Machine 10" would have been quietly overridden by
        // a capability row somebody edited. Worse, a machine that declares
        // nothing at all — which is nine of the ten here — fell through to the
        // global rule and so behaved differently from one that does. The
        // mandatory rule is not a default to be overridden; it is the rule.
        if ($this->isRestricted($cavities)) {
            return in_array($workCenterId, $this->restrictedWorkCenterIds(), true);
        }

        // Below the threshold, the machine's own stored capability governs —
        // unchanged. This is the Machines & Capabilities tab staying the live
        // control for everything the factory rule does not speak to.
        $machine = WorkCenter::query()->find($workCenterId);

        if ($machine !== null) {
            $verdict = $this->machineVerdict($machine, $cavities);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return true;
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
    public function warningFor(?ProductionStandard $standard, ?int $workCenterId, ?int $activeCavities = null): ?array
    {
        // ACTIVE CAVITIES DECIDE, and the standard's figure is only the
        // fallback for callers that have not resolved a run yet.
        //
        // The two can differ because a machine configuration can carry its own
        // default — and a WRONG configuration is exactly how they came to
        // differ for 60 ml Round Amber, where a duplicate automated row said 4
        // against the standard's 5. The rule reads what the run actually uses
        // so that a stale default cannot quietly move a product out of the
        // rule's reach; it is not an endorsement of any particular default.
        $cavities = $activeCavities ?? $standard?->cavities;

        // Compliance is judged here and NOWHERE else consults enforcement to
        // decide whether to speak. A permissive enforcement setting changes
        // what happens next — the run is allowed — but it must never make a
        // non-compliant run look compliant, or the day-one exception log would
        // be empty precisely because enforcement was off.
        if ($this->compliesWithRecommendation($cavities, $workCenterId)) {
            return null;
        }

        $machine = WorkCenter::query()->find($workCenterId);
        $running = $machine?->name ?? 'this machine';

        // When the MACHINE'S OWN capability refused, say what the machine is
        // set up for — that is the figure someone maintains on the Machines &
        // Capabilities tab, and the message should point back at it.
        //
        // Only reachable BELOW the threshold now. At or above it the factory
        // rule decides and this branch must not speak, or a supervisor sent to
        // Machine 10 would instead be told to go and edit a cavity range.
        if (! $this->isRestricted($cavities)
            && $machine !== null
            && $this->machineVerdict($machine, (int) $cavities) === false) {
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
            // The configured CODES match no machine. Reported as the codes
            // themselves rather than silently dropped: a rule pointing at
            // nothing is a configuration error someone needs to see, and the
            // code is what they would go and fix.
            ? 'machine code '.implode(', ', $this->restrictedWorkCenterCodes())
            : implode(' or ', $names);

        return [
            'code' => self::WARNING_CODE,
            'message' => sprintf(
                'This standard runs %d cavities. Moulds of %d or more run only on %s — not on %s.%s',
                (int) $cavities,
                $this->threshold(),
                $allowed,
                $running,
                $this->isEnforced()
                    ? ' This batch cannot be started here.'
                    : ' The batch will record that.',
            ),
        ];
    }
}
