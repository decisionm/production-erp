<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreScrapReasonRequest;
use App\Modules\Production\Http\Resources\ScrapReasonResource;
use App\Modules\Production\Services\ScrapReasonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScrapReasonController extends Controller
{
    public function __construct(private readonly ScrapReasonService $scrapReasons) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ScrapReasonResource::collection($this->scrapReasons->paginate($this->perPage($request)));
    }

    public function store(StoreScrapReasonRequest $request): ScrapReasonResource
    {
        return ScrapReasonResource::make($this->scrapReasons->create($request->validated()));
    }
}
