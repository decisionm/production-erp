<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\TallySync\Http\Requests\UpdateLedgerMappingsRequest;
use App\Modules\TallySync\Http\Requests\UpdateTallyCompanyRequest;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Staff-facing Tally configuration (gated by module:tally-sync): which Tally
 * company this instance syncs with, and the role → ledger-name mappings the
 * voucher builders use. All config lives in the DB, so a differently-set-up
 * client is a settings change here, never a code change.
 */
class TallySettingsController extends Controller
{
    public const KEY_COMPANY = 'tally_company';

    public const KEY_COMPANIES = 'tally_companies';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly TallyLedgerMappingService $mappings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'company' => $this->settings->get(self::KEY_COMPANY),
                'companies' => $this->settings->get(self::KEY_COMPANIES, []),
                'roles' => TallyLedgerRole::options(),
                'mappings' => $this->mappings->all(),
                // Pulled ledgers for the mapping pick-list, each with its Tally
                // ledger group so the UI can group the (long) list by group.
                'ledgers' => Ledger::query()->with('group')->orderBy('name')->get()
                    ->map(fn (Ledger $ledger) => [
                        'name' => $ledger->name,
                        'group' => $ledger->group?->name ?? 'Other',
                    ])->all(),
                // The downloadable Windows installer, published by the
                // build-agent workflow into public storage (null until built).
                'agent' => $this->agentDownload(),
            ],
        ]);
    }

    /**
     * @return array{url: string, version: string|null, built_at: string|null, size: int}|null
     */
    private function agentDownload(): ?array
    {
        $disk = Storage::disk('public');

        // The build workflow writes this metadata alongside the versioned
        // installer + electron-updater's latest.yml; the filename is versioned
        // (e.g. tally-sync-agent-setup-0.1.0.exe), so read it from here.
        if (! $disk->exists('agent/tally-sync-agent-latest.json')) {
            return null;
        }

        $meta = json_decode((string) $disk->get('agent/tally-sync-agent-latest.json'), true) ?: [];
        $filename = $meta['filename'] ?? null;

        if (! is_string($filename) || ! $disk->exists("agent/{$filename}")) {
            return null;
        }

        return [
            // Cache-buster: the filename only changes on version bumps, but
            // rebuilds of the same version replace the file in place — and the
            // hosting CDN caches by URL, so without this a client can be handed
            // a stale (or worse, previously half-uploaded) cached copy whose
            // hash no longer matches this metadata. The commit (or build time)
            // changes every build, forcing a fresh CDN cache key.
            'url' => $disk->url("agent/{$filename}").'?v='.urlencode($meta['commit'] ?? $meta['built_at'] ?? 'unknown'),
            'version' => $meta['version'] ?? null,
            'built_at' => $meta['built_at'] ?? null,
            'sha256' => $meta['sha256'] ?? null,
            'size' => $disk->size("agent/{$filename}"),
        ];
    }

    public function updateCompany(UpdateTallyCompanyRequest $request): JsonResponse
    {
        $company = $request->validated()['company'] ?? null;
        $this->settings->set(self::KEY_COMPANY, $company);

        return response()->json(['data' => ['company' => $company]]);
    }

    public function updateLedgerMappings(UpdateLedgerMappingsRequest $request): JsonResponse
    {
        $this->mappings->setMany($request->validated()['mappings']);

        return response()->json(['data' => ['mappings' => $this->mappings->all()]]);
    }
}
