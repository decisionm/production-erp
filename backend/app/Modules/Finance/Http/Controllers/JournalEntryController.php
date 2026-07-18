<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\StoreJournalEntryRequest;
use App\Modules\Finance\Http\Resources\JournalEntryResource;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalEntryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalEntryService $entries) {}

    public function index(): AnonymousResourceCollection
    {
        return JournalEntryResource::collection($this->entries->paginate());
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
