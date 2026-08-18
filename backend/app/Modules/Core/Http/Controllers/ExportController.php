<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\ExportRequest;
use App\Modules\Core\Http\Resources\ExportRunResource;
use App\Modules\Core\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Download / Export Center (MASTER-PLAN Phase 4.5). Any authenticated
 * user may ask; the catalogue is what filters — a kind is offered, and
 * runnable, only to a reader holding one of its permissions.
 */
class ExportController extends Controller
{
    public function __construct(private readonly ExportService $exports) {}

    /** The kinds this reader may run, blocked ones included (with their reason). */
    public function catalogue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->exports->catalogue($request->user())]);
    }

    /**
     * 404 unknown kind · 403 no permission · 409 blocked (with the reason)
     * · 422 over the cap (with the sentence, matched and cap) — the first
     * three from ExportRequest and ExportService — else the CSV, streamed,
     * as an attachment named `{kind}-{YYYYMMDD-HHMM}.csv` in factory time.
     */
    public function run(ExportRequest $request): StreamedResponse
    {
        return $this->exports->run($request->kind(), $request->validated(), $request->user());
    }

    /** The caller's own recent runs, newest first — successes and refusals alike. */
    public function runs(Request $request): AnonymousResourceCollection
    {
        return ExportRunResource::collection($this->exports->runsFor($request->user()));
    }
}
