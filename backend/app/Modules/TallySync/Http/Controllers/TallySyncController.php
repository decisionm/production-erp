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
    public function retry(TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->sync->retry($tallySyncEntry));
    }
}
