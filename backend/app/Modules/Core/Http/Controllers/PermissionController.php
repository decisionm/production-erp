<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->permissions->catalog()]);
    }
}
