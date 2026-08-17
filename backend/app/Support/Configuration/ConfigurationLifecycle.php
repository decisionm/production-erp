<?php

namespace App\Support\Configuration;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * The ONE place a configuration record's delete / archive / activate is
 * decided — the Configuration Lifecycle Contract (17-Aug-2026) as code.
 * A module declares WHAT references its master (DependencyCheck); it never
 * writes the policy, so twenty-six master screens cannot drift into
 * twenty-six different answers.
 *
 * Generalised from RoleService::delete(), which has been the whole
 * contract for one entity since Phase 0: count the references, refuse with
 * the count, otherwise delete.
 *
 * THREE INVARIANTS, and they are safety, not style:
 *
 * 1. A row is destroyed ONLY when the LOCKED report is completely clear,
 *    and never to make a check pass. Never cascade a parent delete, never
 *    disable FK checks, never force a delete past a blocking or unprovable
 *    verdict. Within that guard the delete is REAL: a SoftDeletes master is
 *    forceDelete()d, a plain one is removed outright (RoleService's
 *    behaviour), because DEC-20260817-002 §1 requires a genuine hard delete
 *    and §2 releases the business code "once the row is gone" — a retained
 *    soft-deleted row would keep reserving its code and satisfy neither.
 *
 *    WHY THAT IS SAFE, and it is safety rather than nerve: eight parents in
 *    this schema cascade to children with no database backstop (payroll
 *    history, stock balances, machine configurations), so for those this
 *    class is the ONLY guard. But a clear report MEANS there are no children
 *    — that is what it proves, re-proved under the row lock a line earlier —
 *    so there is nothing for a cascade to reach. The danger was never
 *    forceDelete() itself; it was forceDelete() used to bypass a check, and
 *    that is what stays forbidden. A cascade-side count above zero is a
 *    REFUSAL and never a cleanup.
 *
 * 2. The served report is ADVISORY; the locked one is authoritative.
 *    delete() opens a transaction, re-reads the row with lockForUpdate(),
 *    and RE-RUNS every check against that locked row before deleting. A
 *    button rendered ten minutes ago and the refusal it triggers can
 *    therefore never disagree.
 *
 * 3. FAIL-CLOSED. A check that cannot prove the record was never used
 *    blocks the delete exactly like a positive count (DEC-20260817-002
 *    §5). Nothing here guesses a number.
 *
 * ARCHIVE is the reversible half: it clears the record's active flag (or,
 * for a master with no flag but with SoftDeletes, soft-deletes it). It
 * deletes nothing, moves no stock, and — per DEC-20260817-002 §4 — causes
 * NO Tally mutation: this class emits no events and touches no Tally
 * field. The archived record KEEPS its business code, which is why the
 * repo's existing global (soft-deleted rows included) code uniqueness is
 * correct and stays (§2).
 *
 * `$reason` is accepted on archive()/activate() because the contract's
 * routes carry one; this class persists it nowhere — there is no reason
 * column, and inventing one is not this pass's work. The audit trail is
 * activitylog's job.
 */
class ConfigurationLifecycle
{
    /** @var array<string, bool> */
    private static array $activeColumns = [];

    /**
     * @param  string  $label  the noun the refusal prints — "item", "warehouse"
     * @param  list<DependencyCheck>  $checks  everything that may reference the record
     * @param  ?string  $activeColumn  the Activate/Deactivate flag, or null when the master has none
     * @param  ?Closure  $nameUsing  how the refusal names one record; defaults to name, then code, then the key
     */
    public function __construct(
        private readonly string $label,
        private readonly array $checks,
        private readonly ?string $activeColumn = 'is_active',
        private readonly ?Closure $nameUsing = null,
    ) {}

    /** What references this record right now. Advisory — see delete(). */
    public function report(Model $model): DependencyReport
    {
        return DependencyReport::for($model, $this->checks);
    }

    /**
     * What may be done to this record — printed as a resource's `can` and
     * enforced by the actions below, so the frontend never re-derives
     * eligibility (the PurchaseOrderResource pattern).
     *
     * `delete` is NULL, never false, when $resolveDelete is false: the
     * honest word for "undetermined — ask". A list of 200 masters would
     * otherwise pay 8–30 COUNTs per row, so index() serves the cheap flags
     * and the confirm dialog fetches show() for the authoritative answer.
     *
     * @return array{edit: bool, activate: bool, archive: bool, delete: bool|null}
     */
    public function abilities(Model $model, bool $resolveDelete = true): array
    {
        $trashed = $this->isTrashed($model);
        $hasFlag = $this->hasActiveColumn($model);
        $isActive = ! $hasFlag || (bool) $model->getAttribute($this->activeColumn);

        return [
            'edit' => ! $trashed,
            'activate' => $trashed || ($hasFlag && ! $isActive),
            'archive' => ! $trashed && $isActive && ($hasFlag || $this->usesSoftDeletes($model)),
            'delete' => match (true) {
                $trashed => false,
                $resolveDelete => $this->report($model)->isClear(),
                default => null,
            },
        ];
    }

    /**
     * Delete the record — refusing, with counts, if ANYTHING references it.
     *
     * @throws ConfigurationInUseException the record is referenced, or its past use cannot be proven
     */
    public function delete(Model $model): void
    {
        $model->getConnection()->transaction(function () use ($model): void {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel($model::class, [$model->getKey()]);
            }

            $report = $this->report($locked);

            if (! $report->isClear()) {
                throw ConfigurationInUseException::for($this->label, $this->nameOf($locked), $report);
            }

            // The report is clear and was re-proved under the lock, so nothing
            // references this row and no cascade can reach a child. Destroy it
            // for real — a soft delete here would retain the row and keep its
            // business code reserved (DEC-20260817-002 §§1-2). See invariant 1.
            $this->usesSoftDeletes($locked) ? $locked->forceDelete() : $locked->delete();
        });
    }

    /** Deactivate (or, with no flag, soft-delete). Reversible; deletes nothing. */
    public function archive(Model $model, ?string $reason = null): Model
    {
        if ($this->hasActiveColumn($model)) {
            $model->setAttribute($this->activeColumn, false);
            $model->save();

            return $model;
        }

        if ($this->usesSoftDeletes($model) && ! $this->isTrashed($model)) {
            $model->delete();

            return $model;
        }

        throw new LogicException(sprintf(
            'This %s has neither an active flag nor soft deletes, so it cannot be archived.',
            $this->label,
        ));
    }

    /** Put an archived record back in service. */
    public function activate(Model $model, ?string $reason = null): Model
    {
        $restored = false;

        if ($this->isTrashed($model)) {
            $model->restore();
            $restored = true;
        }

        if ($this->hasActiveColumn($model)) {
            $model->setAttribute($this->activeColumn, true);
            $model->save();

            return $model;
        }

        if (! $restored) {
            throw new LogicException(sprintf(
                'This %s has neither an active flag nor soft deletes, so it cannot be activated.',
                $this->label,
            ));
        }

        return $model;
    }

    private function nameOf(Model $model): string
    {
        if ($this->nameUsing !== null) {
            return (string) ($this->nameUsing)($model);
        }

        return (string) ($model->getAttribute('name') ?? $model->getAttribute('code') ?? $model->getKey());
    }

    private function hasActiveColumn(Model $model): bool
    {
        if ($this->activeColumn === null) {
            return false;
        }

        $connection = $model->getConnectionName();
        $cacheKey = ($connection ?? 'default').':'.$model->getTable().':'.$this->activeColumn;

        return self::$activeColumns[$cacheKey] ??= Schema::connection($connection)
            ->hasColumn($model->getTable(), $this->activeColumn);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    private function isTrashed(Model $model): bool
    {
        return $this->usesSoftDeletes($model) && $model->trashed();
    }
}
