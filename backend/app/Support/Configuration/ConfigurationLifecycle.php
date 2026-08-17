<?php

namespace App\Support\Configuration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
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
 *    WHY THAT IS SAFE, and it is safety rather than nerve: this schema's
 *    parents cascade to children with no database backstop (payroll
 *    history, stock balances, machine configurations), so for those this
 *    class is the ONLY guard. A clear report MEANS there are no children —
 *    that is what it proves, re-proved under the row lock a line earlier —
 *    so there is nothing for a cascade to reach. The danger was never
 *    forceDelete() itself; it was forceDelete() used to bypass a check, and
 *    that is what stays forbidden. A cascade-side count above zero is a
 *    REFUSAL and never a cleanup.
 *
 *    AND THE DECLARATION IS NOT TAKEN ON TRUST. "A clear report means there
 *    are no children" is only true if the module DECLARED every child, and
 *    a hand-written list will be incomplete one day: with an empty checks
 *    list the old code deleted an employee and the database quietly took
 *    the attendance rows with it. So every report now asks the SCHEMA which
 *    foreign keys cascade into this table (SchemaCascades) and refuses —
 *    naming the table — for any cascading child no check accounts for
 *    (DependencyReport::cascadeGaps()). No module can get this wrong by
 *    forgetting something, because forgetting is what it detects.
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
 * ARCHIVE is the reversible half: it takes the record out of service — it
 * clears a boolean flag, or writes the RETIRED case of a status column (see
 * ActiveFlag; Mold, Asset and MeasuringInstrument carry a BackedEnum
 * `status`, not an `is_active`, and a mechanism that assumed a boolean read
 * a retired mould as active and wrote `false` into its status column), or,
 * for a master with neither but with SoftDeletes, soft-deletes it. It
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
 *
 * WHO MAY HARD-DELETE is DEC-20260817-002 §3: Super Admin / Owner only,
 * while ordinary configuration users may create, edit, activate and
 * deactivate by RBAC. This repo has no such role and no such permission
 * today, and inventing either here would be inventing policy. What lives
 * here instead is the SEAM, in the one place the delete is decided: a
 * `$canHardDelete` callback receiving the acting user. It is FAIL-CLOSED —
 * with no callback declared, no hard delete happens at all, because the
 * honest reading of "Super Admin only" for a system that cannot yet name a
 * Super Admin is "not yet, and not by default". The wiring wave supplies
 * the callback (and the permission behind it) when the routes are built.
 */
class ConfigurationLifecycle
{
    /** @var array<string, bool> */
    private static array $activeColumns = [];

    private readonly ?ActiveFlag $activeFlag;

    /**
     * @param  string  $label  the noun the refusal prints — "item", "warehouse"
     * @param  list<DependencyCheck>  $checks  everything that may reference the record
     * @param  ActiveFlag|string|null  $activeColumn  the Activate/Deactivate flag: a column name for the ordinary boolean master, an ActiveFlag for a status-enum one, null when the master has neither
     * @param  ?Closure  $nameUsing  how the refusal names one record; defaults to name, then code, then the key
     * @param  ?Closure  $canHardDelete  fn (?Authenticatable): bool — DEC-20260817-002 §3's seam; null refuses every hard delete
     */
    public function __construct(
        private readonly string $label,
        private readonly array $checks,
        ActiveFlag|string|null $activeColumn = 'is_active',
        private readonly ?Closure $nameUsing = null,
        private readonly ?Closure $canHardDelete = null,
    ) {
        $this->activeFlag = ActiveFlag::from($activeColumn);
    }

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
     * `delete` distinguishes three answers and the difference is load-bearing:
     *   FALSE  — a decision. Either this user may not hard-delete at all
     *            (DEC-20260817-002 §3), or the record is referenced.
     *   NULL   — undetermined, ask. Only when $resolveDelete is false: a
     *            list of 200 masters would otherwise pay 8–30 COUNTs per
     *            row, so index() serves the cheap flags and the confirm
     *            dialog fetches show() for the authoritative answer.
     *   TRUE   — this user may, and the record is provably unused.
     * An unauthorised user is answered FALSE rather than null, because that
     * is not an unknown: no amount of counting would change it.
     *
     * `activate` and `archive` are asked as SEPARATE questions, not as each
     * other's opposite — see ActiveFlag. A mould whose status is
     * `under_repair` is neither in service nor retired, so both are offered.
     *
     * A trashed (archived-by-soft-delete) record is answered exactly like
     * any other for `delete`: DEC-20260817-002 §1 permits a hard delete of a
     * record proven never-used, and being archived does not make it used.
     * delete() below finds the same row, so the two can never disagree.
     *
     * @return array{edit: bool, activate: bool, archive: bool, delete: bool|null}
     */
    public function abilities(Model $model, bool $resolveDelete = true, ?Authenticatable $user = null): array
    {
        $trashed = $this->isTrashed($model);
        $hasFlag = $this->hasActiveColumn($model);
        $isActive = ! $hasFlag || $this->activeFlag->isActive($model);
        $isRetired = $hasFlag && $this->activeFlag->isRetired($model);

        return [
            'edit' => ! $trashed,
            'activate' => $trashed || ($hasFlag && ! $isActive),
            'archive' => ! $trashed && ! $isRetired && ($hasFlag || $this->usesSoftDeletes($model)),
            'delete' => match (true) {
                ! $this->mayHardDelete($user) => false,
                $resolveDelete => $this->report($model)->isClear(),
                default => null,
            },
        ];
    }

    /**
     * Delete the record — refusing, with counts, if ANYTHING references it.
     *
     * The acting user is checked FIRST (DEC-20260817-002 §3): a user who may
     * not hard-delete is told so without being told what the record is used
     * by, and no count is paid to reach that answer.
     *
     * An ARCHIVED record is found here, not 404'd: soft-deleting is this
     * contract's Archive, so a row that was archived and is provably unused
     * gets the contract's answer — the refusal with its reasons, or the
     * delete — rather than "no such record".
     *
     * @throws AuthorizationException this user may not hard-delete
     * @throws ConfigurationInUseException the record is referenced, its past use cannot be proven, or a cascading child is undeclared
     */
    public function delete(Model $model, ?Authenticatable $user = null): void
    {
        if (! $this->mayHardDelete($user)) {
            throw new AuthorizationException(sprintf(
                'Deleting a %s outright is reserved to Super Admin / Owner (DEC-20260817-002 §3). Deactivate instead.',
                $this->label,
            ));
        }

        $model->getConnection()->transaction(function () use ($model): void {
            $locked = $this->query($model)->whereKey($model->getKey())->lockForUpdate()->first();

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

    /** Take out of service (or, with no flag, soft-delete). Reversible; deletes nothing. */
    public function archive(Model $model, ?string $reason = null): Model
    {
        if ($this->hasActiveColumn($model)) {
            $this->activeFlag->markRetired($model);
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
            $this->activeFlag->markActive($model);
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

    /**
     * Whether this user may hard-delete AT ALL — DEC-20260817-002 §3's seam.
     * No callback declared means no hard delete: the mechanism will not
     * assume an authority the repo cannot yet express.
     */
    private function mayHardDelete(?Authenticatable $user = null): bool
    {
        if ($this->canHardDelete === null) {
            return false;
        }

        return (bool) ($this->canHardDelete)($user ?? Auth::user());
    }

    /** The query delete() looks the row up with — archived rows included. */
    private function query(Model $model): Builder
    {
        $query = $model->newQuery();

        return $this->usesSoftDeletes($model) ? $query->withTrashed() : $query;
    }

    private function hasActiveColumn(Model $model): bool
    {
        if ($this->activeFlag === null) {
            return false;
        }

        $connection = $model->getConnectionName();
        $cacheKey = ($connection ?? 'default').':'.$model->getTable().':'.$this->activeFlag->column;

        return self::$activeColumns[$cacheKey] ??= Schema::connection($connection)
            ->hasColumn($model->getTable(), $this->activeFlag->column);
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
