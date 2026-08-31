<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ConfirmTallyVendorFieldsRequest;
use App\Modules\Procurement\Http\Requests\DismissTallyVendorDifferenceRequest;
use App\Modules\Procurement\Http\Requests\UpdateTallyVendorGroupsRequest;
use App\Modules\Procurement\Http\Resources\VendorResource;
use App\Modules\Procurement\Services\TallyVendorReviewService;
use App\Modules\TallySync\Services\AgentIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * THE ADMIN/ACCOUNTS REVIEW BETWEEN TALLY AND THE VENDOR MASTER.
 *
 * Nothing on this controller runs by itself. The masters pull mirrors ledgers
 * and stops there; every vendor created or corrected from Tally is an
 * Owner/Accounts login pressing confirm on a difference it can see, which is
 * what the owner asked for and what AGENTS.md requires of a live master-data
 * change.
 *
 * TWO GATES, BOTH REQUIRED, and neither is new. The route group is
 * `module:finance`, the same gate supplier bills sit behind and for the same
 * stated reason — supplier identity is Owner/Accounts (FC-06), so a
 * procurement login without finance access must not read this list at all.
 * On top of it, every action re-asks AgentIdentity::mayReadPurchaseDetails,
 * the module's single FC-06 predicate. No new permission name is minted:
 * RoleService intersects every grant with PermissionService::MODULES, so a
 * name outside that catalogue is stripped from every role on the next save of
 * the Roles screen and the gate would then refuse everyone, silently.
 */
class TallyVendorReviewController extends Controller
{
    public function __construct(private readonly TallyVendorReviewService $review) {}

    /** The queue of decisions owed, computed fresh — see the service. */
    public function index(Request $request): JsonResponse
    {
        $this->authoriseReader($request);

        return response()->json(['data' => $this->review->queue()]);
    }

    /**
     * Name the Tally groups whose ledgers are candidate vendors.
     *
     * An owner act, kept out of code deliberately: this factory's Sundry
     * Creditors group holds an INTEREST ledger whose name differs from a real
     * supplier's by two letters, and the company's own second GST
     * registration. Which creditor is a supplier is not something an agent may
     * infer.
     */
    public function updateGroups(UpdateTallyVendorGroupsRequest $request): JsonResponse
    {
        $this->authoriseReader($request);

        return $this->guard(function () use ($request): JsonResponse {
            $this->review->setVendorGroups($request->validated()['groups']);

            return response()->json(['data' => $this->review->queue()]);
        });
    }

    /** Create the vendor a "new" row proposes. */
    public function confirmNew(Request $request): JsonResponse
    {
        $this->authoriseReader($request);

        $validated = $request->validate(['tally_ledger_guid' => ['required', 'string', 'max:255']]);

        return $this->guard(fn () => response()->json([
            'data' => VendorResource::make($this->review->confirmNew($validated['tally_ledger_guid'])),
        ], 201));
    }

    /** Apply the named differences — and only those — to the matched vendor. */
    public function confirmFields(ConfirmTallyVendorFieldsRequest $request): JsonResponse
    {
        $this->authoriseReader($request);

        $data = $request->validated();

        return $this->guard(fn () => response()->json([
            'data' => VendorResource::make(
                $this->review->confirmFields($data['tally_ledger_guid'], $data['vendor_id'], $data['fields']),
            ),
        ]));
    }

    /** Set one difference, or a whole ledger, aside — against the value seen. */
    public function dismiss(DismissTallyVendorDifferenceRequest $request): JsonResponse
    {
        $this->authoriseReader($request);

        $data = $request->validated();

        return $this->guard(function () use ($data, $request): JsonResponse {
            $this->review->dismiss($data['tally_ledger_guid'], $data['field'], $request->user());

            return response()->json(['data' => $this->review->queue()]);
        });
    }

    private function authoriseReader(Request $request): void
    {
        abort_unless(
            AgentIdentity::mayReadPurchaseDetails($request->user()),
            403,
            'Supplier details from Tally are Owner/Accounts only (FC-06).',
        );
    }

    /**
     * The service refuses in business words — a name already taken, a ledger
     * that has left the mirror, a value Tally no longer carries. Those are 422s
     * a person can act on, not 500s.
     *
     * @param  callable(): JsonResponse  $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (RuntimeException $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 422);
        }
    }
}
