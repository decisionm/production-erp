<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\BulkResolveAttendanceImportLinesRequest;
use App\Modules\HRMS\Http\Requests\ListAttendanceImportEmployeesRequest;
use App\Modules\HRMS\Http\Requests\ListAttendanceImportLinesRequest;
use App\Modules\HRMS\Http\Requests\ListAttendanceImportsRequest;
use App\Modules\HRMS\Http\Requests\ResolveAttendanceImportLineRequest;
use App\Modules\HRMS\Http\Requests\StoreAttendanceImportRequest;
use App\Modules\HRMS\Http\Resources\AttendanceImportEmployeeResource;
use App\Modules\HRMS\Http\Resources\AttendanceImportLineResource;
use App\Modules\HRMS\Http\Resources\AttendanceImportResource;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Services\AttendanceImportService;
use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The punch-report import: upload (as parsed rows), review, correct,
 * apply. Thin by the module rule — every verdict is AttendanceImportService's.
 * Under `module:hrms`: the GETs need hrms.view, the writes hrms.manage.
 */
class AttendanceImportController extends Controller
{
    public function __construct(private readonly AttendanceImportService $imports) {}

    public function index(ListAttendanceImportsRequest $request, HrmsListQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return AttendanceImportResource::collection($this->imports->paginate($query->perPage($filters), $filters));
    }

    public function store(StoreAttendanceImportRequest $request): JsonResponse
    {
        return AttendanceImportResource::make($this->imports->create($request->validated(), $request->user()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $attendance_import): AttendanceImportResource
    {
        return AttendanceImportResource::make($this->imports->find($attendance_import) ?? abort(404));
    }

    public function lines(ListAttendanceImportLinesRequest $request, HrmsListQuery $query, AttendanceImport $attendance_import): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return AttendanceImportLineResource::collection(
            $this->imports->paginateLines($attendance_import, $query->perPage($filters), $filters),
        );
    }

    public function employees(
        ListAttendanceImportEmployeesRequest $request,
        HrmsListQuery $query,
        AttendanceImport $attendance_import,
    ): AnonymousResourceCollection {
        $filters = $request->validated();

        return AttendanceImportEmployeeResource::collection(
            $this->imports->paginateEmployees($attendance_import, $query->perPage($filters), $filters),
        );
    }

    /**
     * One answer for one kind of problem. Returns what it did — answered,
     * skipped, and whose codes were skipped — plus the run's fresh counts,
     * so the screen states the outcome instead of re-reading and guessing.
     */
    public function bulkResolve(
        BulkResolveAttendanceImportLinesRequest $request,
        AttendanceImport $attendance_import,
    ): JsonResponse {
        $result = $this->imports->resolveMany($attendance_import, $request->validated(), $request->user());

        return response()->json([
            ...$result,
            'import' => AttendanceImportResource::make($this->imports->fresh($attendance_import))->resolve(),
        ]);
    }

    /** Re-judge the days nobody has answered under the current hours rule. */
    public function recheck(Request $request, AttendanceImport $attendance_import): JsonResponse
    {
        $result = $this->imports->recheck($attendance_import);

        return response()->json([
            ...$result,
            'import' => AttendanceImportResource::make($this->imports->fresh($attendance_import))->resolve(),
        ]);
    }

    public function resolveLine(
        ResolveAttendanceImportLineRequest $request,
        AttendanceImport $attendance_import,
        AttendanceImportLine $line,
    ): AttendanceImportLineResource {
        abort_unless($line->attendance_import_id === $attendance_import->id, 404);

        return AttendanceImportLineResource::make($this->imports->resolve($line, $request->validated(), $request->user()));
    }

    public function apply(Request $request, AttendanceImport $attendance_import): AttendanceImportResource
    {
        return AttendanceImportResource::make($this->imports->apply($attendance_import, $request->user()));
    }
}
