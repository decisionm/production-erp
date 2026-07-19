<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreBomRequest;
use App\Modules\Production\Http\Resources\BomResource;
use App\Modules\Production\Services\BomService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BomController extends Controller
{
    public function __construct(private readonly BomService $boms) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BomResource::collection($this->boms->paginate($request->integer('item_id') ?: null));
    }

    public function store(StoreBomRequest $request): BomResource
    {
        return BomResource::make($this->boms->create($request->validated()));
    }
}
