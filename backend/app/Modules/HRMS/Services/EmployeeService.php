<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use App\Support\Configuration\ActiveFlag;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The employee master — and, under the Configuration Lifecycle Contract,
 * THE HIGHEST-RISK PARENT IN THIS SCHEMA.
 *
 * Four tables cascade from `employees` with no database backstop —
 * `attendances`, `leave_balances`, `leave_requests`, `salary_structures` —
 * and they hold statutory attendance and payroll history. Six more are
 * SET NULL, which is worse in one specific way: a cascade at least destroys
 * something a backstop could be built for, while SET NULL leaves the row
 * standing with the fact quietly removed from it. Nothing here may be
 * incomplete, and the declaration below is written against the schema as
 * read from the database rather than from memory.
 */
class EmployeeService
{
    use ManagesConfigurationLifecycle;

    public function __construct(private readonly HrmsListQuery $query) {}

    /**
     * The list page's read. Every filter is ListEmployeesRequest's — `q`
     * over code, name, department and designation (one clause, shared with
     * the leave and attendance lists), `status` exact. Ordered by name as it
     * always was, with id breaking ties so two employees of one name never
     * swap places between page loads.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = HrmsListQuery::PER_PAGE_DEFAULT, array $filters = []): LengthAwarePaginator
    {
        $query = Employee::query()->with(['manager']);

        if (($term = $this->query->term($filters)) !== null) {
            $this->query->whereEmployeeMatches($query, $term);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Active employees for other modules to aggregate over (e.g. Payroll
     * run generation). Not paginated: this is meant for batch processing,
     * not a list screen.
     */
    public function active(): Collection
    {
        return Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): Employee
    {
        return Employee::create([
            'status' => 'active',
            ...$data,
        ])->load('manager');
    }

    public function update(Employee $employee, array $data): Employee
    {
        $employee->update($data);

        return $employee->load('manager');
    }

    /**
     * One employee, ARCHIVED ROWS INCLUDED — the contract's View, and the
     * lookup the delete / archive / activate routes bind on. A soft-deleted
     * master has to stay reachable or Activate could never undo an Archive,
     * and delete() answers for a trashed row rather than 404ing it.
     */
    public function find(int $id): ?Employee
    {
        return Employee::withTrashed()->with('manager')->find($id);
    }

    protected function configurationLabel(): string
    {
        return 'employee';
    }

    /**
     * THREE STATES, so two separate predicates — see ActiveFlag.
     *
     * Active is in service. Inactive is what Archive writes. TERMINATED IS
     * DELIBERATELY NOT THE RETIRED CASE: "terminated" is a statement about
     * an employment relationship that only HR can make, and mapping the
     * generic Deactivate button onto it would have the ERP assert an HR fact
     * nobody stated. It therefore sits as the third case — neither active
     * nor the archive target — and abilities() below says what may be done
     * to a row already in it.
     */
    protected function configurationActiveColumn(): ActiveFlag|string|null
    {
        return ActiveFlag::status(
            'status',
            active: EmployeeStatus::Active,
            retired: EmployeeStatus::Inactive,
        );
    }

    protected function configurationNameUsing(): ?Closure
    {
        return static fn (Employee $employee): string => trim(
            (string) $employee->name.' ('.(string) $employee->employee_code.')'
        );
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }

    /**
     * A TERMINATED employee is already out of service, so there is nothing
     * to archive; offering Deactivate there would silently downgrade a
     * termination to "inactive" and lose the stronger fact. Activate stays
     * offered — a re-hire is an explicit act the employee form has always
     * allowed — and so does the delete question, which termination answers
     * neither way.
     *
     * @return array{edit: bool, activate: bool, archive: bool, delete: bool|null}
     */
    public function abilities(Model $model, bool $resolveDelete = true, ?Authenticatable $user = null): array
    {
        $abilities = $this->configurationLifecycle()->abilities($model, $resolveDelete, $user);

        if ($model->status === EmployeeStatus::Terminated) {
            $abilities['archive'] = false;
        }

        return $abilities;
    }

    /**
     * Archive — take the employee out of service.
     *
     * The ONE thing this adds to the shared mechanism is the refusal
     * abilities() already announces: a TERMINATED employee has nothing to
     * deactivate, and letting the generic write through would overwrite the
     * termination with the weaker "inactive". The rule is one rule — the
     * server decides, and the server enforces what it decided — so the
     * predicate is asked here rather than trusted to have been read off the
     * button. Everything else, including the already-inactive refusal, stays
     * the mechanism's.
     */
    public function archive(Model $model, ?string $reason = null): Model
    {
        if ($model->status === EmployeeStatus::Terminated) {
            throw ValidationException::withMessages([
                'status' => 'This employee is already terminated, so there is nothing to deactivate. Reactivate them first if they have returned.',
            ]);
        }

        return $this->configurationLifecycle()->archive($model, $reason);
    }

    /**
     * EVERYTHING THAT MAY REFER TO AN EMPLOYEE. Read from the schema on
     * 18-Aug-2026, every table asked, grouped by what the database would
     * actually DO on a real DELETE.
     *
     * CASCADE — destroyed silently, no database backstop, this list is the
     * only guard (the audit's finding 3):
     *   attendances.employee_id
     *   leave_balances.employee_id
     *   leave_requests.employee_id
     *   salary_structures.employee_id
     * All four are marked ->cascadeSide(), which is also what lets the schema
     * backstop see them as DECLARED; a count above zero is a refusal and
     * never a cleanup, and the delete-referenced test asserts that every one
     * of these children is still there afterwards.
     *
     * RESTRICT — the database would refuse, but as a raw driver error:
     *   payslips.employee_id
     * Declared so the answer is the contract's 422 with a count and an
     * Archive offer, rather than a 500 nobody can act on. FC-06: a COUNT of
     * payslips, never an amount — no payroll figure is printed here or
     * anywhere in the refusal.
     *
     * SET NULL — no backstop at all, and the quietest failure of the three:
     * the row survives with the employee silently removed from it.
     *   employees.manager_id                     (their reports lose a manager)
     *   capas.owner                              (a QMS action loses its owner)
     *   maintenance_work_orders.assigned_to
     *   shift_production_entries.operator_id
     *   shift_production_entries.supervisor_signed_by  (a SIGNATURE on a
     *                                             posted production document)
     *   shift_summaries.supervisor_id
     * The two shift-entry columns are ONE check with two columns, OR-ed, so
     * an employee referenced only as the signatory is still counted.
     *
     * CHECKED NEGATIVES, stated so a later reader knows they were looked at
     * and not forgotten:
     *   · `shift_production_entries.helper_name` is free text a supervisor
     *     types (CompleteBatchRequest: a plain string, max 120). Nothing
     *     anywhere resolves it against this table, so it is not a reference
     *     to an employee row and there is nothing to count. If a helper ever
     *     becomes a picked employee, THAT is when a check belongs here.
     *   · `employees.user_id` points the other way — at a login, not at this
     *     row.
     *   · No settings key, in `app_settings` or `factory_settings`, names an
     *     employee id.
     *   · Nothing in this repo matches an employee by name.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            // ---- cascade side: the only guard there is -------------------
            DependencyCheck::table('attendances', 'employee_id')
                ->label('attendance record')
                ->cascadeSide(),
            DependencyCheck::table('leave_balances', 'employee_id')
                ->label('leave balance')
                ->cascadeSide(),
            DependencyCheck::table('leave_requests', 'employee_id')
                ->label('leave request')
                ->cascadeSide(),
            DependencyCheck::table('salary_structures', 'employee_id')
                ->label('salary structure')
                ->cascadeSide(),

            // ---- restrict side: make the refusal the contract's ----------
            DependencyCheck::table('payslips', 'employee_id')
                ->label('payslip'),

            // ---- set-null side: the quietest of the three ----------------
            // includeTrashed because `employees` soft-deletes and this column
            // is SET NULL: a manager whose only report is ARCHIVED otherwise
            // reads as clear, and hard-deleting them blanks that archived
            // record's manager without a word. An archived employee is still
            // a physical row the database will act on.
            DependencyCheck::table('employees', 'manager_id')
                ->label('direct report')->includeTrashed(),
            DependencyCheck::table('capas', 'owner')
                ->label('CAPA owned'),
            DependencyCheck::table('maintenance_work_orders', 'assigned_to')
                ->label('maintenance work order'),
            DependencyCheck::table('shift_production_entries', ['operator_id', 'supervisor_signed_by'])
                ->label('shift production entry'),
            DependencyCheck::table('shift_summaries', 'supervisor_id')
                ->label('shift summary'),
            // The punch-report import's review copy (03-Sep). SET NULL: a
            // line would survive with its employee blanked, which is the
            // quiet failure this list exists to count.
            DependencyCheck::table('attendance_import_lines', 'employee_id')
                ->label('attendance import line'),
        ];
    }
}
