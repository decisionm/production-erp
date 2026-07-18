<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\StoreGLAccountRequest;
use App\Modules\Finance\Http\Requests\UpdateGLAccountRequest;
use App\Modules\Finance\Http\Resources\GLAccountResource;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Services\GLAccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GLAccountController extends Controller
{
    public function __construct(private readonly GLAccountService $accounts) {}

    public function index(): AnonymousResourceCollection
    {
        return GLAccountResource::collection($this->accounts->paginate());
    }

    public function store(StoreGLAccountRequest $request): GLAccountResource
    {
        return GLAccountResource::make($this->accounts->create($request->validated()));
    }

    public function update(UpdateGLAccountRequest $request, GLAccount $glAccount): GLAccountResource
    {
        return GLAccountResource::make($this->accounts->update($glAccount, $request->validated()));
    }
}
