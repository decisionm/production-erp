<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreMoldRequest;
use App\Modules\Production\Http\Requests\UpdateMoldRequest;
use App\Modules\Production\Http\Resources\MoldResource;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Services\MoldService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MoldController extends Controller
{
    public function __construct(private readonly MoldService $molds) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MoldResource::collection($this->molds->paginate($this->perPage($request)));
    }

    public function store(StoreMoldRequest $request): MoldResource
    {
        return MoldResource::make($this->molds->create($request->validated()));
    }

    public function update(UpdateMoldRequest $request, Mold $mold): MoldResource
    {
        return MoldResource::make($this->molds->update($mold, $request->validated()));
    }
}
