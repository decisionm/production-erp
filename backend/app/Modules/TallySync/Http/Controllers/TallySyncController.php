<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The admin dashboard view — staff browsing the SPA, ordinary session auth.
 * The agent-facing endpoints live in TallySyncAgentController instead,
 * gated by token abilities rather than just auth:sanctum.
 */
class TallySyncController extends Controller
{
    public function __construct(private readonly TallySyncService $sync) {}

    public function index(): AnonymousResourceCollection
    {
        return TallySyncEntryResource::collection($this->sync->paginate());
    }

    public function retry(TallySyncEntry $tallySyncEntry): TallySyncEntryResource
    {
        return TallySyncEntryResource::make($this->sync->retry($tallySyncEntry));
    }
}
