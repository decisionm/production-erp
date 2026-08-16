<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The admin dashboard view — staff browsing the SPA, ordinary session auth.
 * The agent-facing endpoints live in TallySyncAgentController instead,
 * gated by token abilities rather than just auth:sanctum.
 */
class TallySyncController extends Controller
{
    public function __construct(private readonly TallySyncService $sync) {}

    /**
     * `per_page` (the shared Controller::perPage clamp) so the dashboard can
     * pull the whole queue in one request instead of the newest 20. It has to
     * be able to: a failed voucher is only unmissable if the page can see
     * every failed voucher, and the default first page hides anything older
     * than the last 20 entries — which, on a busy day, is where a Tally
     * rejection from yesterday morning quietly lives.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TallySyncEntryResource::collection($this->sync->paginate($this->perPage($request)));
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
}
