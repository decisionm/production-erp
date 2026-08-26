<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Http\Requests\ListTallySyncEntriesRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\SyncNowAuthority;
use App\Modules\TallySync\Services\TallySyncQueryService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The admin dashboard view — staff browsing the SPA, ordinary session auth.
 * The agent-facing endpoints live in TallySyncAgentController instead,
 * gated by token abilities rather than just auth:sanctum.
 *
 * Reads go through TallySyncQueryService, writes through TallySyncService —
 * the split TALLY-SYNC-CHAIN.md §3 draws so the read model can grow
 * filters without a single line of the posting path moving.
 */
class TallySyncController extends Controller
{
    public function __construct(
        private readonly TallySyncService $sync,
        private readonly TallySyncQueryService $queries,
    ) {}

    /**
     * The queue, filtered by whatever the request validated
     * (ListTallySyncEntriesRequest) — status, category, voucher type,
     * business-date range, free text, shift, machine, held, direction —
     * in the same newest-first order as always unless `sort=status_rank`.
     *
     * `per_page` (the shared Controller::perPage clamp) so the dashboard can
     * pull the whole queue in one request instead of the newest 20. It has to
     * be able to: a failed voucher is only unmissable if the page can see
     * every failed voucher, and the default first page hides anything older
     * than the last 20 entries — which, on a busy day, is where a Tally
     * rejection from yesterday morning quietly lives.
     */
    public function index(ListTallySyncEntriesRequest $request): AnonymousResourceCollection
    {
        return TallySyncEntryResource::collection(
            $this->queries->paginate($request->validated(), $this->perPage($request), $request->user()),
        );
    }

    /**
     * One voucher with its full history — the same resource the list
     * returns, plus `history` (its tally_sync_events, oldest first). The
     * relation is loaded by the query service's show() and nowhere else,
     * which is what keeps `history` off the list.
     */
    public function show(TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->queries->show($tallySyncEntry));
    }

    /**
     * The Control Center's header: today's (factory-day) and all-time
     * counts, one count per catalogue category, and when the agent and Tally
     * were last heard from. Takes the list's filters so the counts can
     * follow the page.
     */
    public function summary(ListTallySyncEntriesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->summary($request->validated(), $request->user())]);
    }

    /**
     * Re-queue a voucher for the agent. 422s (from the service) when the
     * voucher is already synced — the dashboard only offers Retry on failed
     * rows, but a stale page or any other API client can still ask, and
     * re-queueing a voucher Tally has accepted posts it into the live books
     * twice.
     */
    public function retry(Request $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->sync->retry($tallySyncEntry, $request->user()?->id, $request->user()));
    }

    /**
     * Write a dead voucher off — it will never be sent to Tally. 422s
     * (from the service) unless the voucher is failed-and-never-synced:
     * the dashboard only offers Dismiss on failed rows, but a stale page
     * or another API client can still ask, and a "dismissed" label over a
     * voucher that is pending or already in the books would be a lie.
     */
    public function dismiss(Request $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->sync->dismiss($tallySyncEntry, $request->user()?->id, $request->user()));
    }

    /**
     * The accountant's "Release now" on a held shift voucher
     * (DEC-20260807-011) — skips the rest of the shift-end/idle wait and
     * lets the agent collect on its next poll. 422s (from the service)
     * when the voucher is not actually being held, so a stale page gets
     * told what happened instead of a no-op dressed as success.
     */
    public function release(Request $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->sync->releaseNow($tallySyncEntry, $request->user()?->id, $request->user()));
    }

    /**
     * "Sync Now" — DEC-20260825-002. A REQUEST that the vouchers already
     * queued in this ERP go out on the agent's next poll; the service
     * (requestSyncNow) does the whole of it in one transaction and reaches
     * Tally in no way whatsoever.
     *
     * OWNER/ACCOUNTS, SERVER-SIDE. The route group already demands
     * tally-sync.manage for a POST; SyncNowAuthority adds the Owner/Accounts
     * half the owner named, spelled the way FC-06 already spells it. 403,
     * not a hidden button: the page hides the control for a reader who may
     * not press it, and a hidden button is a courtesy, never the gate.
     *
     * ALWAYS 200 WHEN IT RAN. "Nothing is queued" and "everything queued was
     * already on its way" are true, useful answers, not errors — the body's
     * `outcome` says which of the three happened
     * (released | already_queued | nothing_queued) and the counts say how
     * much. The only failure codes here are the authorization ones.
     */
    public function syncNow(Request $request): JsonResponse
    {
        abort_unless(
            SyncNowAuthority::mayRequest($request->user()),
            403,
            'Sync Now is limited to Owner/Accounts logins. Ask the owner or Accounts to send the queue.',
        );

        $result = $this->sync->requestSyncNow($request->user()?->id, $request->user());

        return response()->json([
            'data' => $result + [
                // The liveness the page needs to word its answer honestly:
                // a request made while the agent has not checked in for
                // hours has NOT posted anything, and must not read as if it
                // had. A timestamp only — never which token, never its
                // abilities.
                'agent' => ['last_checked_at' => AgentIdentity::lastCheckedAt()?->toIso8601String()],
            ],
        ]);
    }
}
