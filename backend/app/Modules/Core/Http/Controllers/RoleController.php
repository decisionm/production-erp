<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreRoleRequest;
use App\Modules\Core\Http\Requests\UpdateRoleRequest;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Modules\Core\Services\RoleService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection($this->roles->list());
    }

    public function store(StoreRoleRequest $request): RoleResource
    {
        return RoleResource::make($this->roles->create($request->validated())->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return RoleResource::make($this->roles->update($role, $request->validated())->loadCount('users'));
    }

    public function destroy(Role $role): Response
    {
        $this->roles->delete($role);

        return response()->noContent();
    }
}
