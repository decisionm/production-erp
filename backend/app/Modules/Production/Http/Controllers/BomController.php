<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ListBomsRequest;
use App\Modules\Production\Http\Requests\StoreBomRequest;
use App\Modules\Production\Http\Resources\BomResource;
use App\Modules\Production\Services\BomService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BomController extends Controller
{
    public function __construct(private readonly BomService $boms) {}

    public function index(ListBomsRequest $request): AnonymousResourceCollection
    {
        return BomResource::collection($this->boms->paginate($request->itemId(), $request->perPage(), $request->sort()));
    }

    public function store(StoreBomRequest $request): BomResource
    {
        return BomResource::make($this->boms->create($request->validated()));
    }
}
