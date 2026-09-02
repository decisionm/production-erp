<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Http\Requests\ListUsersRequest;
use App\Modules\Core\Http\Requests\ResetUserPasswordRequest;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Requests\UpdateUserRequest;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginate(
            (int) ($request->validated('per_page') ?? 20),
            $request->validated('sort'),
        ));
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = $this->users->create($request->validated());

        return UserResource::make($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->update($user, $request->validated(), $request->user()?->id));
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $this->users->resetPassword($user, $request->validated('password'));

        return response()->json(['message' => 'Password reset.']);
    }
}
