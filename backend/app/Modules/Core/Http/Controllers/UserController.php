<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Services\UserService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginate());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = $this->users->create($request->validated());

        return UserResource::make($user);
    }
}
