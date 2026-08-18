<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\StoreEmployeeRequest;
use App\Modules\HRMS\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\EmployeeService;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The employee master's Create → View → Edit → Activate/Deactivate → Safe
 * Delete → Audit (DEC-20260817-002). Thin by the module rule: every verdict
 * — what may be done, what blocks a delete, who may delete at all — is
 * EmployeeService's, and this class only says which one to ask for.
 *
 * `show` carries the AUTHORITATIVE `can`, index the cheap one with
 * `delete: null` (undetermined — ask), because resolving delete on a list
 * costs ten COUNTs a row.
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->employees->paginate($this->perPage($request)));
    }

    /** One employee, archived rows included, with the authoritative `can`. */
    public function show(int $employee): EmployeeResource
    {
        return EmployeeResource::make($this->stamped($this->resolve($employee)));
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        return EmployeeResource::make($this->employees->create($request->validated()));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return EmployeeResource::make($this->employees->update($employee, $request->validated()));
    }

    /**
     * Take the employee out of service. Reversible, destroys nothing, and
     * causes no Tally mutation (DEC-20260817-002 §4).
     */
    public function archive(ConfigurationReasonRequest $request, int $employee): EmployeeResource
    {
        $this->refuseStaleButton($employee, 'archive');
        $this->employees->archive($this->resolve($employee), $request->reason());

        return EmployeeResource::make($this->stamped($this->resolve($employee)));
    }

    public function activate(ConfigurationReasonRequest $request, int $employee): EmployeeResource
    {
        $this->refuseStaleButton($employee, 'activate');
        $this->employees->activate($this->resolve($employee), $request->reason());

        return EmployeeResource::make($this->stamped($this->resolve($employee)));
    }

    /**
     * Hard delete — Super Admin / Owner only, and only for an employee
     * proven never used. Every refusal comes back as the shared 422 with
     * counts and an Archive offer; the authorisation failure comes back as
     * 403 without paying for a single count. Neither answer is composed
     * here.
     */
    public function destroy(Request $request, int $employee): Response
    {
        $this->employees->delete($this->resolve($employee), $request->user());

        return response()->noContent();
    }

    private function resolve(int $employee): Employee
    {
        return $this->employees->find($employee) ?? abort(404);
    }

    /**
     * Stamp the AUTHORITATIVE `can` — delete resolved, not left as
     * "undetermined" — on a single-record answer. The PurchaseOrder pattern:
     * a plain public property, never an Eloquent attribute, so it can never
     * be written back to the row.
     *
     * INTERSECTED WITH THIS USER'S GRANT. `module:hrms` lets `hrms.view`
     * alone through a GET but not through any write, so a read-only user who
     * was handed the record's structural eligibility would see four buttons
     * and get a 403 from each. What the RECORD allows and what the ROLE
     * allows are different questions and the `can` block is their
     * intersection.
     *
     * (Production's ManagesConfigurationRecords does exactly this for the
     * floor's masters. It is not reused here because it lives in that
     * module's namespace and cross-module reach-in is what the module rule
     * forbids — lifting it into App\Support\Configuration\Http would let
     * both modules share one copy, and is a pure move.)
     */
    private function stamped(Employee $employee): Employee
    {
        $employee->can = $this->mayWrite()
            ? $this->employees->abilities($employee)
            : ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false];

        return $employee;
    }

    /**
     * A STALE BUTTON is an ordinary thing for a user to produce — two people
     * on one master screen, or one tab left open — and the mechanism answers
     * it with a LogicException, which renders as a 500. The mechanism's OWN
     * verdict is asked first and rendered as a business refusal instead. The
     * verdict is not recomputed and the mechanism still enforces it behind
     * this. (Production's ManagesConfigurationRecords does the same for the
     * floor's masters with a shared exception; lifting that trait into
     * App\Support\Configuration\Http would let both modules share it.)
     */
    private function refuseStaleButton(int $employee, string $action): void
    {
        if ($this->employees->abilities($this->resolve($employee), resolveDelete: false)[$action]) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => $action === 'archive'
                ? 'This employee is already out of service, so there is nothing to deactivate.'
                : 'This employee is already in service, so there is nothing to reactivate.',
        ]);
    }

    private function mayWrite(): bool
    {
        $user = request()->user();

        return $user !== null
            && method_exists($user, 'hasAnyPermission')
            && $user->hasAnyPermission(['hrms.manage']);
    }
}
