<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Resources\FulfilmentControlRowResource;
use App\Modules\Sales\Services\FulfilmentControlService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Sales Fulfilment Control view — READ ONLY, and shared by every team.
 *
 * There is deliberately no write action here. Holding, releasing, re-pointing
 * and asking the floor for a shortfall stay on the store's fulfilment queue;
 * starting and reordering stay on the production queue; dispatching and
 * invoicing stay on Sales. This controller exists so all of them can see the
 * same picture before they act on their own screen.
 */
class FulfilmentControlController extends Controller
{
    public function __construct(private readonly FulfilmentControlService $control) {}

    public function index(): AnonymousResourceCollection
    {
        return FulfilmentControlRowResource::collection($this->control->rows());
    }
}
