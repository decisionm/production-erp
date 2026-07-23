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
                // Pulled ledger names for the mapping pick-list.
                'ledgers' => Ledger::query()->orderBy('name')->pluck('name')->all(),
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
        $installer = 'agent/tally-sync-agent-setup.exe';

        if (! $disk->exists($installer)) {
            return null;
        }

        $meta = $disk->exists('agent/tally-sync-agent-latest.json')
            ? (json_decode((string) $disk->get('agent/tally-sync-agent-latest.json'), true) ?: [])
            : [];

        return [
            'url' => $disk->url($installer),
            'version' => $meta['version'] ?? null,
            'built_at' => $meta['built_at'] ?? null,
            'size' => $disk->size($installer),
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
