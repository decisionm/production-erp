<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Http\Requests\FailTallySyncEntryRequest;
use App\Modules\TallySync\Http\Requests\SyncItemsRequest;
use App\Modules\TallySync\Http\Requests\SyncMastersRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\ItemSyncService;
use App\Modules\TallySync\Services\MasterSyncService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The local sync agent's endpoints — meant to be called with a Sanctum
 * personal access token scoped to exactly these abilities, not a
 * general-purpose token or an admin's full session. A leaked agent token
 * can poll and report sync status; it can't do anything else. A
 * session-authenticated SPA user gets Sanctum's "transient token" (all
 * abilities) automatically, so staff can still exercise these from the
 * dashboard if needed — the restriction is what a token-only client is
 * limited to, not what staff can do.
 */
class TallySyncAgentController extends Controller
{
    public function __construct(private readonly TallySyncService $sync) {}

    public function pending(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->tokenCan('tally-sync:poll'), 403, 'Token missing the tally-sync:poll ability.');

        return TallySyncEntryResource::collection($this->sync->pending());
    }

    public function acknowledge(Request $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        abort_unless($request->user()?->tokenCan('tally-sync:report'), 403, 'Token missing the tally-sync:report ability.');

        return TallySyncEntryResource::make($this->sync->markSynced($tallySyncEntry));
    }

    public function fail(FailTallySyncEntryRequest $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        abort_unless($request->user()?->tokenCan('tally-sync:report'), 403, 'Token missing the tally-sync:report ability.');

        return TallySyncEntryResource::make(
            $this->sync->markFailed($tallySyncEntry, $request->validated()['error_message']),
        );
    }

    /**
     * Inbound masters pull: the agent posts the Tally stock-item list here and
     * we upsert it into the ERP item master (matched on GUID). Idempotent — the
     * agent re-posts the full list each cycle; re-posting is safe.
     */
    public function items(SyncItemsRequest $request, ItemSyncService $itemSync): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('tally-sync:items'), 403, 'Token missing the tally-sync:items ability.');

        return response()->json([
            'data' => $itemSync->sync($request->validated()['items']),
        ]);
    }

    /**
     * Full masters pull: item groups, godowns, ledger groups, ledgers and items
     * in one call, processed in dependency order. Every section is optional and
     * idempotent. Returns a per-section created/updated/total summary.
     */
    public function masters(SyncMastersRequest $request, MasterSyncService $masterSync): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('tally-sync:masters'), 403, 'Token missing the tally-sync:masters ability.');

        return response()->json([
            'data' => $masterSync->sync($request->validated()),
        ]);
    }
}
