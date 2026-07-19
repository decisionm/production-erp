<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Http\Requests\StoreAssetRequest;
use App\Modules\Maintenance\Http\Requests\UpdateAssetRequest;
use App\Modules\Maintenance\Http\Resources\AssetResource;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Services\AssetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(): AnonymousResourceCollection
    {
        return AssetResource::collection($this->assets->paginate());
    }

    public function store(StoreAssetRequest $request): AssetResource
    {
        return AssetResource::make($this->assets->create($request->validated()));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        return AssetResource::make($this->assets->update($asset, $request->validated()));
    }
}
