<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CloseDowntimeLogRequest;
use App\Modules\Production\Http\Requests\OpenDowntimeLogRequest;
use App\Modules\Production\Http\Resources\MachineDowntimeLogResource;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Services\MachineDowntimeLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MachineDowntimeLogController extends Controller
{
    public function __construct(private readonly MachineDowntimeLogService $downtimeLogs) {}

    public function index(): AnonymousResourceCollection
    {
        return MachineDowntimeLogResource::collection($this->downtimeLogs->paginate());
    }

    public function open(OpenDowntimeLogRequest $request): MachineDowntimeLogResource
    {
        return MachineDowntimeLogResource::make(
            $this->downtimeLogs->open($request->validated(), $request->user()?->id),
        );
    }

    public function close(CloseDowntimeLogRequest $request, MachineDowntimeLog $machineDowntimeLog): MachineDowntimeLogResource
    {
        return MachineDowntimeLogResource::make(
            $this->downtimeLogs->close($machineDowntimeLog, $request->validated()),
        );
    }
}
