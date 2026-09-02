<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Http\Requests\ListAssetsRequest;
use App\Modules\Maintenance\Http\Requests\StoreAssetRequest;
use App\Modules\Maintenance\Http\Requests\UpdateAssetRequest;
use App\Modules\Maintenance\Http\Resources\AssetResource;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Services\AssetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    /**
     * The asset list. `per_page` is honoured so a PICKER can ask for the
     * whole master: its dropdown offers ACTIVE rows only now, and
     * filtering the first 20 would hide part of a list that was already
     * truncated (the item/vendor picker defect, 12-Aug). The default is
     * unchanged for every other caller. `sort` is one of
     * ListAssetsRequest::SORTABLE, bare or "-" prefixed (03-Sep-2026).
     */
    public function index(ListAssetsRequest $request): AnonymousResourceCollection
    {
        return AssetResource::collection($this->assets->paginate($this->perPage($request), $request->validated('sort')));
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
