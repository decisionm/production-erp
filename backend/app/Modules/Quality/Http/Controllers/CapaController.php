<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\CloseCapaRequest;
use App\Modules\Quality\Http\Requests\StoreCapaRequest;
use App\Modules\Quality\Http\Requests\UpdateCapaRequest;
use App\Modules\Quality\Http\Resources\CapaResource;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Services\CapaService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CapaController extends Controller
{
    public function __construct(private readonly CapaService $capas) {}

    public function index(): AnonymousResourceCollection
    {
        return CapaResource::collection($this->capas->paginate());
    }

    public function store(StoreCapaRequest $request): CapaResource
    {
        return CapaResource::make($this->capas->create($request->validated(), $request->user()?->id));
    }

    public function update(UpdateCapaRequest $request, Capa $capa): CapaResource
    {
        return CapaResource::make($this->capas->update($capa, $request->validated()));
    }

    public function start(Capa $capa): CapaResource
    {
        return CapaResource::make($this->capas->start($capa));
    }

    public function close(CloseCapaRequest $request, Capa $capa): CapaResource
    {
        return CapaResource::make($this->capas->close($capa, (bool) $request->validated('verified_effective')));
    }
}
