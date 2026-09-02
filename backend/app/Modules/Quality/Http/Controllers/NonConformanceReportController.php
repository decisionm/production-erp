<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\CloseNonConformanceReportRequest;
use App\Modules\Quality\Http\Requests\ListNonConformanceReportsRequest;
use App\Modules\Quality\Http\Requests\StoreNonConformanceReportRequest;
use App\Modules\Quality\Http\Resources\NonConformanceReportResource;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NonConformanceReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NonConformanceReportController extends Controller
{
    public function __construct(private readonly NonConformanceReportService $reports) {}

    public function index(ListNonConformanceReportsRequest $request): AnonymousResourceCollection
    {
        return NonConformanceReportResource::collection($this->reports->paginate(
            perPage: $request->perPage(),
            sort: $request->sort(),
        ));
    }

    public function store(StoreNonConformanceReportRequest $request): NonConformanceReportResource
    {
        $report = $this->reports->create($request->validated(), $request->user()?->id);

        return NonConformanceReportResource::make($report);
    }

    public function close(CloseNonConformanceReportRequest $request, NonConformanceReport $ncr): NonConformanceReportResource
    {
        return NonConformanceReportResource::make(
            $this->reports->close($ncr, $request->validated()['resolution']),
        );
    }
}
