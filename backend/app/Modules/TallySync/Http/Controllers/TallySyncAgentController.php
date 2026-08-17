<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Http\Requests\FailTallySyncEntryRequest;
use App\Modules\TallySync\Http\Requests\StockSummaryPreviewRequest;
use App\Modules\TallySync\Http\Requests\StoreTallySyncSnapshotRequest;
use App\Modules\TallySync\Http\Requests\SyncCompaniesRequest;
use App\Modules\TallySync\Http\Requests\SyncMastersRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Http\Resources\TallySyncSnapshotResource;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\MasterSyncService;
use App\Modules\TallySync\Services\StockSummaryPreviewService;
use App\Modules\TallySync\Services\TallySyncEventRecorder;
use App\Modules\TallySync\Services\TallySyncService;
use App\Modules\TallySync\Services\TallySyncSnapshotService;
use App\Modules\TallySync\Services\TransactionClassifier;
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
    /**
     * $events is the durable twin of agentLog(): the file keeps its
     * per-call line, and the same event lands in tally_sync_events where it
     * is queryable and joinable. Voucher-side events (delivered, synced,
     * failed) are recorded by the SERVICE, which is why the request user is
     * passed through below and not recorded here a second time; only the
     * Tally→ERP flows that create no entry are recorded from this class.
     */
    public function __construct(
        private readonly TallySyncService $sync,
        private readonly TallySyncEventRecorder $events,
    ) {}

    public function pending(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->tokenCan('tally-sync:poll'), 403, 'Token missing the tally-sync:poll ability.');

        // The polling agent is the actor on each 'pending.delivered' row.
        $entries = $this->sync->pending($request->user());

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

        return TallySyncEntryResource::make($this->sync->markSynced($tallySyncEntry, $request->user()));
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
        $result = $this->sync->markFailed($tallySyncEntry, $error, $request->user());

        $this->agentLog($request, $result->isInTally() ? 'voucher.failure_refused' : 'voucher.failed', [
            'entry_id' => $tallySyncEntry->id,
            'voucher_type' => $tallySyncEntry->tally_voucher_type,
            'voucher_number' => $tallySyncEntry->payload['voucher_number'] ?? null,
            'error' => $error,
        ]);

        return TallySyncEntryResource::make($result);
    }

    /**
     * The agent's post-Tally SNAPSHOT — the XML it sent and what Tally
     * answered — for one entry (Phase 4; MASTER-PLAN P4-01..05). Same
     * ability as ack/fail: it is a report about a post.
     *
     * A RECORD, NOT A REPORT ON STATUS: nothing here touches the entry — not
     * its status, attempts, error_message or payload — and the agent uploads
     * it fire-and-forget AFTER its ack/fail path has run, so a lost upload
     * changes nothing about what reached Tally or what the cloud was told.
     * 201 with the snapshot's public shape; 200 with the SAME row when the
     * agent's client retried an upload whose response was lost (same entry
     * + sha256 + attempt inside the service's window) — never a double.
     * The agent reads back through the same reader gate as everyone else,
     * and being the agent it reads what it sent (FC-06 predicate).
     */
    public function snapshot(
        StoreTallySyncSnapshotRequest $request,
        TallySyncEntry $tallySyncEntry,
        TallySyncSnapshotService $snapshots,
        TransactionClassifier $classifier,
    ): JsonResponse {
        abort_unless($request->user()?->tokenCan('tally-sync:report'), 403, 'Token missing the tally-sync:report ability.');

        $snapshot = $snapshots->store($tallySyncEntry, $request->validated(), $request->user());

        // Counts, ids and the hash only — never the XML or Tally's text
        // (reader-gated on the snapshot; FC-06). `repeated` marks the
        // idempotent replay so the file says the upload arrived twice.
        $this->agentLog($request, 'snapshot.stored', [
            'entry_id' => $tallySyncEntry->id,
            'voucher_type' => $tallySyncEntry->tally_voucher_type,
            'voucher_number' => $tallySyncEntry->payload['voucher_number'] ?? null,
            'snapshot_id' => $snapshot->id,
            'attempt' => $snapshot->attempt,
            'xml_sha256' => $snapshot->xml_sha256,
            'xml_bytes' => $snapshot->xml_bytes,
            'has_body' => $snapshot->xml !== null,
            'tally_success' => $snapshot->tally_success,
            'tally_created' => $snapshot->tally_created,
            'tally_errors' => $snapshot->tally_errors,
            'agent_version' => $snapshot->agent_version,
            'payload_matches' => $snapshot->payload_matches,
            'repeated' => ! $snapshot->wasRecentlyCreated,
        ]);

        return TallySyncSnapshotResource::forCategory(
            $snapshot,
            $classifier->classify($tallySyncEntry),
            AgentIdentity::mayReadPurchaseDetails($request->user()),
        )->response()->setStatusCode($snapshot->wasRecentlyCreated ? 201 : 200);
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
            $this->events->record(
                TallySyncEventKind::CompanyBound,
                null,
                ['company' => $incoming],
                TallySyncEvent::DIRECTION_TALLY_TO_ERP,
                $request->user(),
            );
        }

        $summary = $masterSync->sync($data, $incoming);

        $this->agentLog($request, 'masters.received', ['company' => $incoming] + $summary);
        // The first database record of an inbound pull ever: counts and the
        // company, no master rows (those are on their own tables).
        $this->events->record(
            TallySyncEventKind::MastersReceived,
            null,
            ['company' => $incoming] + $summary,
            TallySyncEvent::DIRECTION_TALLY_TO_ERP,
            $request->user(),
        );

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
        // Counts only — never the item lines or their rates, which live on
        // the snapshot behind its own permission (FC-06).
        $this->events->record(
            TallySyncEventKind::StockSummaryPreviewed,
            null,
            [
                'snapshot_id' => $snapshot->id,
                'company' => $incoming,
                'as_of' => $data['as_of'],
            ] + $result['totals'],
            TallySyncEvent::DIRECTION_TALLY_TO_ERP,
            $request->user(),
        );

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
        $this->events->record(
            TallySyncEventKind::CompaniesReceived,
            null,
            ['companies' => $companies],
            TallySyncEvent::DIRECTION_TALLY_TO_ERP,
            $request->user(),
        );

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
