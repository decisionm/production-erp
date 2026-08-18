<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\StoreGLAccountRequest;
use App\Modules\Finance\Http\Requests\UpdateGLAccountRequest;
use App\Modules\Finance\Http\Resources\GLAccountResource;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Services\GLAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GLAccountController extends Controller
{
    public function __construct(private readonly GLAccountService $accounts) {}

    /**
     * The account list. `per_page` is honoured so a PICKER can ask for the
     * whole master: its dropdown offers ACTIVE rows only now, and
     * filtering the first 20 would hide part of a list that was already
     * truncated (the item/vendor picker defect, 12-Aug). The default is
     * unchanged for every other caller.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return GLAccountResource::collection($this->accounts->paginate($this->perPage($request)));
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
