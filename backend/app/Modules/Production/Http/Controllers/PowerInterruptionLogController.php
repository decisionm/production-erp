<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StorePowerInterruptionLogRequest;
use App\Modules\Production\Http\Resources\PowerInterruptionLogResource;
use App\Modules\Production\Services\PowerInterruptionLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PowerInterruptionLogController extends Controller
{
    public function __construct(private readonly PowerInterruptionLogService $powerInterruptionLogs) {}

    public function index(): AnonymousResourceCollection
    {
        return PowerInterruptionLogResource::collection($this->powerInterruptionLogs->paginate());
    }

    public function store(StorePowerInterruptionLogRequest $request): PowerInterruptionLogResource
    {
        return PowerInterruptionLogResource::make(
            $this->powerInterruptionLogs->create($request->validated(), $request->user()?->id),
        );
    }
}
