<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Http\Requests\FailTallySyncEntryRequest;
use App\Modules\TallySync\Http\Requests\StockSummaryPreviewRequest;
use App\Modules\TallySync\Http\Requests\SyncCompaniesRequest;
use App\Modules\TallySync\Http\Requests\SyncItemsRequest;
use App\Modules\TallySync\Http\Requests\SyncMastersRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\ItemSyncService;
use App\Modules\TallySync\Services\MasterSyncService;
use App\Modules\TallySync\Services\StockSummaryPreviewService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

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

        $entries = $this->sync->pending();

        // Polls run every ~90s; only log the interesting ones (non-empty),
        // or the file becomes wall-to-wall no-ops.
        if ($entries->isNotEmpty()) {
            $this->agentLog($request, 'pending.delivered', [
                'count' => $entries->count(),
                'ids' => $entries->pluck('id')->all(),
            ]);
        }

        return TallySyncEntryResource::collection($entries);
    }

    public function acknowledge(Request $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        abort_unless($request->user()?->tokenCan('tally-sync:report'), 403, 'Token missing the tally-sync:report ability.');

        $this->agentLog($request, 'voucher.synced', [
            'entry_id' => $tallySyncEntry->id,
            'voucher_type' => $tallySyncEntry->tally_voucher_type,
            'voucher_number' => $tallySyncEntry->payload['voucher_number'] ?? null,
        ]);

        return TallySyncEntryResource::make($this->sync->markSynced($tallySyncEntry));
    }

    public function fail(FailTallySyncEntryRequest $request, TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        abort_unless($request->user()?->tokenCan('tally-sync:report'), 403, 'Token missing the tally-sync:report ability.');

        $error = $request->validated()['error_message'];

        // Logged from the RESULT, not the request: a failure reported for a
        // voucher already in Tally is refused by the service (it would put a
        // Retry button on a voucher that is in the books), and the agent
        // trace has to say that plainly rather than record a failure that
        // never happened.
        $result = $this->sync->markFailed($tallySyncEntry, $error);

        $this->agentLog($request, $result->isInTally() ? 'voucher.failure_refused' : 'voucher.failed', [
            'entry_id' => $tallySyncEntry->id,
            'voucher_type' => $tallySyncEntry->tally_voucher_type,
            'voucher_number' => $tallySyncEntry->payload['voucher_number'] ?? null,
            'error' => $error,
        ]);

        return TallySyncEntryResource::make($result);
    }

    /**
     * Inbound masters pull: the agent posts the Tally stock-item list here and
     * we upsert it into the ERP item master (matched on GUID). Idempotent — the
     * agent re-posts the full list each cycle; re-posting is safe.
     */
    public function items(SyncItemsRequest $request, ItemSyncService $itemSync): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('tally-sync:items'), 403, 'Token missing the tally-sync:items ability.');

        $summary = $itemSync->sync($request->validated()['items']);

        $this->agentLog($request, 'items.received', $summary);

        return response()->json([
            'data' => $summary,
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

            $this->agentLog($request, 'company.bound', ['company' => $incoming]);
        }

        $summary = $masterSync->sync($data, $incoming);

        $this->agentLog($request, 'masters.received', ['company' => $incoming] + $summary);

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * A godown-wise Stock Summary from the agent, REPORTED ON AND DISCARDED.
     *
     * Writes nothing — no item, no warehouse, no stock movement, no voucher.
     * The agent reads Tally's closing position read-only, this reports what it
     * would mean here, and importing it as opening stock is a separate act a
     * person approves after reading the report.
     *
     * Company-guarded exactly like the masters pull, and for the same reason:
     * six godowns from another company are already in this system because
     * nothing checked. Here the guard is unconditional — there is no
     * trust-on-first-use branch, because an opening balance is never the thing
     * that should be allowed to bind an instance to a company.
     */
    public function stockSummaryPreview(
        StockSummaryPreviewRequest $request,
        StockSummaryPreviewService $preview,
        AppSettingService $settings,
    ): JsonResponse {
        abort_unless($request->user()?->tokenCan('tally-sync:masters'), 403, 'Token missing the tally-sync:masters ability.');

        $data = $request->validated();
        $incoming = $data['company'];
        $bound = $settings->get(TallySettingsController::KEY_COMPANY);

        abort_if(
            $bound !== null && $bound !== $incoming,
            409,
            "This ERP is bound to Tally company '{$bound}'. Refusing a stock summary from '{$incoming}'.",
        );

        abort_if(
            $bound === null,
            409,
            'No Tally company is selected for this ERP yet. Choose one in Settings before reading a stock summary.',
        );

        // The company's GUID prefix, learned from the one godown this instance
        // already holds for it. Lines pointing at a godown outside that prefix
        // are flagged as another company's.
        $result = $preview->preview($data['lines'], $this->boundCompanyGuidPrefix());

        // KEPT SO A PERSON CAN READ IT. Storing is not applying: this row moves
        // no stock and posts nothing. Before this, the answer went back to the
        // agent and the server kept nothing, so the only way to see what Tally
        // said was to read a log file on the factory PC — and a snapshot nobody
        // can look at never becomes an opening balance anybody trusts.
        $snapshot = TallyStockSnapshot::create([
            'company' => $incoming,
            'as_of' => $data['as_of'],
            'lines' => $result['lines'],
            'totals' => $result['totals'],
            'status' => TallyStockSnapshot::STATUS_PENDING,
            'created_by' => $request->user()?->id,
        ]);

        $this->agentLog($request, 'stock-summary.previewed', [
            'snapshot_id' => $snapshot->id,
            'company' => $incoming,
            'as_of' => $data['as_of'],
        ] + $result['totals']);

        return response()->json([
            'data' => [
                'snapshot_id' => $snapshot->id,
                'company' => $incoming,
                'as_of' => $data['as_of'],
                'imported' => false,
                'totals' => $result['totals'],
                'lines' => $result['lines'],
            ],
        ]);
    }

    /**
     * The GUID prefix of the company this instance is bound to, derived from
     * the warehouses already synced for it. Null when it cannot be established
     * — in which case the preview simply does not make a cross-company claim,
     * rather than inventing one.
     */
    private function boundCompanyGuidPrefix(): ?string
    {
        $prefixes = Warehouse::query()
            ->whereNotNull('tally_guid')
            ->pluck('tally_guid')
            ->map(fn (string $guid) => implode('-', array_slice(explode('-', $guid), 0, 5)))
            ->countBy();

        // The majority prefix among synced godowns is this company's. With the
        // foreign rows outnumbering the real one this would be wrong, which is
        // exactly why it returns null unless one prefix is unambiguous.
        return $prefixes->count() === 1 ? (string) $prefixes->keys()->first() : null;
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

        $this->agentLog($request, 'companies.received', ['companies' => $companies]);

        return response()->json(['data' => ['companies' => $companies]]);
    }

    /**
     * Append-only trace of everything the agent sends us, one line per call,
     * in its own DAY-WISE file (storage/logs/tally-agent-YYYY-MM-DD.log) so
     * agent traffic can be inspected per day without wading through the app
     * log, and old days age out on their own. Token name identifies WHICH
     * installation sent it — one token per site by convention.
     */
    private function agentLog(Request $request, string $event, array $context = []): void
    {
        Log::build([
            'driver' => 'daily',
            'path' => storage_path('logs/tally-agent.log'),
            'days' => 30,
        ])->info($event, [
            'token' => $request->user()?->currentAccessToken()?->name ?? 'session',
        ] + $context);
    }
}
