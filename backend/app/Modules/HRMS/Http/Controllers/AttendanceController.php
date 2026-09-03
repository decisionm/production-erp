<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\AttendanceMineRequest;
use App\Modules\HRMS\Http\Requests\AttendancePersonRequest;
use App\Modules\HRMS\Http\Requests\AttendanceSummaryRequest;
use App\Modules\HRMS\Http\Requests\ListAttendanceRequest;
use App\Modules\HRMS\Http\Requests\MarkAttendanceRequest;
use App\Modules\HRMS\Http\Resources\AttendanceDayResource;
use App\Modules\HRMS\Http\Resources\AttendanceResource;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\AttendanceService;
use App\Modules\HRMS\Services\HrmsListQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(ListAttendanceRequest $request, HrmsListQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return AttendanceDayResource::collection($this->attendance->paginate($query->perPage($filters), $filters));
    }

    public function mark(MarkAttendanceRequest $request): AttendanceResource
    {
        return AttendanceResource::make($this->attendance->mark($request->validated()));
    }

    /**
     * MY OWN ATTENDANCE — the top of the page, for whoever is logged in.
     *
     * Outside the HRMS permission gate on purpose: a person's own
     * attendance is theirs, and a packer who may not open Employees still
     * has a right to see what the factory has recorded against their name.
     * The read is bounded by WHO IS ASKING — there is no employee
     * parameter, so no login can reach anybody else's days through it.
     *
     * A login with no employee row behind it gets an empty month rather
     * than an error: not every user is a member of the factory's staff.
     */
    public function me(AttendanceMineRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $employee = Employee::query()->where('user_id', $request->user()?->id)->first();

        return response()->json([
            'data' => $this->attendance->mine($employee, $filters['from'], $filters['to']),
        ]);
    }

    /** One person's days over one range — the top half of the page. */
    public function person(AttendancePersonRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $employee = Employee::query()->findOrFail((int) $filters['employee_id']);

        return response()->json([
            'data' => $this->attendance->personRange($employee, $filters['from'], $filters['to']),
        ]);
    }

    /**
     * The same month, on paper. The floor corrects attendance on paper
     * already, so the sheet is what a supervisor hands over and gets back
     * written on.
     */
    public function sheet(AttendancePersonRequest $request): Response
    {
        $filters = $request->validated();
        $employee = Employee::query()->findOrFail((int) $filters['employee_id']);

        $sheet = $this->attendance->monthSheet($employee, $filters['from'], $filters['to']);
        $name = "attendance-{$employee->employee_code}-{$filters['from']}-to-{$filters['to']}.pdf";

        return Pdf::loadView('pdf.attendance-month', $sheet)->download($name);
    }

    /**
     * THE READS THAT ARE NOT A COUNT: turnout day by day, how long the days
     * ran, and who the punch report keeps failing on.
     *
     * The same `hrms.manage` this controller asks of `summary`, and for the
     * same reason — these are the whole factory's numbers, and a supervisor
     * looking one person up is not being handed them.
     */
    public function insights(AttendanceSummaryRequest $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasAnyPermission(['hrms.manage']) === true,
            403,
            "You don't have permission to access this feature."
        );

        $filters = $request->validated();

        return response()->json([
            'data' => $this->attendance->insights($filters['from'], $filters['to']),
        ]);
    }

    /**
     * The factory's attendance for a range, by department.
     *
     * `module:hrms` lets a GET through on `.view` OR `.manage`, and this
     * read is the whole factory's numbers — so the stricter half is asked
     * for HERE. A supervisor may look one person up without being handed
     * everybody's attendance.
     */
    public function summary(AttendanceSummaryRequest $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasAnyPermission(['hrms.manage']) === true,
            403,
            "You don't have permission to access this feature."
        );

        $filters = $request->validated();

        return response()->json([
            'data' => $this->attendance->departmentSummary($filters['from'], $filters['to']),
        ]);
    }
}
