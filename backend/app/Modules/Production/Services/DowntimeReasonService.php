<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DowntimeReason;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The downtime reason master, extracted from DowntimeReasonController.
 *
 * The controller queried the model directly, which is the one shape that
 * cannot take the shared configuration lifecycle: the trait declares what
 * references a master and is answered by ConfigurationLifecycle, and a
 * controller is not where that declaration belongs (CLAUDE.md — controllers
 * hold no Eloquent). The reads and writes below are the controller's
 * previous behaviour moved, unchanged, so that the lifecycle has a service
 * to live in.
 */
class DowntimeReasonService
{
    use ManagesConfigurationLifecycle;

    /**
     * @param  bool  $selectableAtStart  the Start Batch planned-downtime
     *                                   picker's contract: offerable AND in
     *                                   service. Unchanged from the
     *                                   controller.
     * @return Collection<int, DowntimeReason>
     */
    public function list(bool $selectableAtStart = false): Collection
    {
        return DowntimeReason::query()
            ->when($selectableAtStart, fn ($q) => $q->where('selectable_at_start', true)->where('is_active', true))
            ->orderBy('planning_type')
            ->orderBy('code')
            ->get();
    }

    /**
     * Refreshed before it is returned, because four of this table's columns
     * carry DATABASE defaults (is_active, reduces_runtime, requires_note,
     * selectable_at_start) and a freshly created model that was never sent
     * them reports them as null — so the store response said "unknown"
     * about a row the database had already decided. The row is the answer.
     */
    public function create(array $data): DowntimeReason
    {
        return DowntimeReason::create($data)->refresh();
    }

    public function update(DowntimeReason $downtimeReason, array $data): DowntimeReason
    {
        $downtimeReason->update($data);

        return $downtimeReason->fresh();
    }

    protected function configurationLabel(): string
    {
        return 'downtime reason';
    }

    /**
     * WHAT REFERENCES A DOWNTIME REASON — one child, and it is the audit's
     * flagged case:
     *
     *   production_downtime_events.downtime_reason_id   ON DELETE CASCADE
     *
     * CASCADE means the database has NO backstop: a delete that got past
     * this check would take every downtime event recorded under the reason
     * with it, silently, and the idle-time report would simply have less
     * history than it had yesterday. ->cascadeSide() declares it, and the
     * schema backstop (SchemaCascades) independently re-derives the same
     * cascade from the database and refuses if this list ever stops
     * covering it.
     *
     * `production_downtime_events` does not soft-delete, so there are no
     * archived children to count separately; ->cascadeSide() would have
     * counted them anyway.
     *
     * NOT a reference: `machine_downtime_logs.nature_of_problem` is free
     * text a person types, never a foreign key and never matched against
     * this master — a reason is not "used" because somebody typed a similar
     * sentence, and reading it as one would invent a dependency.
     *
     * A downtime reason carries no Tally identity and reaches no voucher:
     * lost minutes are an ERP figure, not an accounting one.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('production_downtime_events', 'downtime_reason_id')
                ->label('downtime event')->cascadeSide(),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return ConfigurationDeleteTier::authorisation();
    }
}
