<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\OpenMoldChangeLogRequest;
use App\Modules\Production\Http\Resources\MoldChangeLogResource;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Services\MoldChangeLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MoldChangeLogController extends Controller
{
    public function __construct(private readonly MoldChangeLogService $moldChangeLogs) {}

    public function index(): AnonymousResourceCollection
    {
        return MoldChangeLogResource::collection($this->moldChangeLogs->paginate());
    }

    public function open(OpenMoldChangeLogRequest $request): MoldChangeLogResource
    {
        return MoldChangeLogResource::make(
            $this->moldChangeLogs->open($request->validated(), $request->user()?->id),
        );
    }

    public function close(MoldChangeLog $moldChangeLog): MoldChangeLogResource
    {
        return MoldChangeLogResource::make($this->moldChangeLogs->close($moldChangeLog));
    }
}
