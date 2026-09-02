<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\ListJournalEntriesRequest;
use App\Modules\Finance\Http\Requests\StoreJournalEntryRequest;
use App\Modules\Finance\Http\Resources\JournalEntryResource;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalEntryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalEntryService $entries) {}

    public function index(ListJournalEntriesRequest $request): AnonymousResourceCollection
    {
        return JournalEntryResource::collection($this->entries->paginate(
            (int) ($request->validated('per_page') ?? 20),
            $request->validated('sort'),
        ));
    }

    public function store(StoreJournalEntryRequest $request): JournalEntryResource
    {
        $entry = $this->entries->create($request->validated(), $request->user()?->id);

        return JournalEntryResource::make($entry);
    }

    public function post(JournalEntry $journalEntry): JournalEntryResource
    {
        return JournalEntryResource::make($this->entries->post($journalEntry));
    }
}
