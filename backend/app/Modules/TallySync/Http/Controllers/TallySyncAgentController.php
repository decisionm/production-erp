<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\TallySync\Http\Requests\FailTallySyncEntryRequest;
use App\Modules\TallySync\Http\Requests\SyncCompaniesRequest;
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
    public function masters(SyncMastersRequest $request, MasterSyncService $masterSync, AppSettingService $settings): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('tally-sync:masters'), 403, 'Token missing the tally-sync:masters ability.');

        $data = $request->validated();
        $incoming = $data['company'] ?? null;
        $bound = $settings->get(TallySettingsController::KEY_COMPANY);

        // Single-tenant guard: one ERP instance syncs exactly one Tally company.
        // Once bound, refuse masters from any OTHER company so a misconfigured
        // agent can't overwrite/mix another company's items and ledgers.
        // Trust-on-first-use: the first pull binds the instance if nothing's set.
        abort_if(
            $bound !== null && $incoming !== null && $bound !== $incoming,
            409,
            "This ERP is bound to Tally company '{$bound}'. Refusing a masters pull from '{$incoming}' — that would mix two companies' data. Select the correct company in Settings, or reconfigure the agent to the right company.",
        );

        if ($bound === null && $incoming !== null) {
            $settings->set(TallySettingsController::KEY_COMPANY, $incoming);
        }

        return response()->json([
            'data' => $masterSync->sync($data),
        ]);
    }

    /**
     * The agent reports the list of companies it found in the local Tally, so
     * staff can pick which one to sync from in Settings. Tally's company list
     * needs no company loaded, so this can run before a company is selected.
     */
    public function companies(SyncCompaniesRequest $request, AppSettingService $settings): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('tally-sync:masters'), 403, 'Token missing the tally-sync:masters ability.');

        $companies = array_values(array_unique($request->validated()['companies']));
        $settings->set(TallySettingsController::KEY_COMPANIES, $companies);

        return response()->json(['data' => ['companies' => $companies]]);
    }
}
