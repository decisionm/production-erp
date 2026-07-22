<?php

namespace App\Modules\TallySync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TallySync\Http\Requests\StoreAgentTokenRequest;
use App\Modules\TallySync\Http\Resources\AgentTokenResource;
use App\Modules\TallySync\Services\AgentTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Staff-facing token management for the local sync agent — gated behind
 * module:tally-sync same as the rest of this module's routes, which means
 * POST/DELETE here require the tally-sync.manage permission specifically
 * (see EnsureModulePermission), not just view access. Distinct from
 * TallySyncAgentController, which is what the agent itself calls once it
 * has a token from here.
 */
class TallySyncAgentTokenController extends Controller
{
    public function __construct(private readonly AgentTokenService $tokens) {}

    public function index(): AnonymousResourceCollection
    {
        return AgentTokenResource::collection($this->tokens->listTokens());
    }

    public function store(StoreAgentTokenRequest $request): JsonResponse
    {
        $result = $this->tokens->issueToken($request->validated()['name']);

        // plain_text_token rides alongside the resource, deliberately not
        // inside it — see AgentTokenResource's doc comment for why.
        return response()->json([
            'data' => AgentTokenResource::make($result['token']),
            'plain_text_token' => $result['plainTextToken'],
        ], 201);
    }

    public function destroy(int $tokenId): Response
    {
        $this->tokens->revokeToken($tokenId);

        return response()->noContent();
    }
}
