<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Http\Requests\AskQuestionRequest;
use App\Modules\Assistant\Http\Requests\ListConversationsRequest;
use App\Modules\Assistant\Http\Requests\StoreConversationRequest;
use App\Modules\Assistant\Http\Resources\ConversationResource;
use App\Modules\Assistant\Http\Resources\MessageResource;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\AskErpService;
use App\Modules\Assistant\Services\ProviderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AskErpController extends Controller
{
    private const string UNTITLED = 'New question';

    public function __construct(private readonly AskErpService $service) {}

    public function catalogue(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->catalogueFor($request->user()),
            // What the page leads with: questions to click, not table names.
            'examples' => $this->service->examplesFor($request->user()),
            'configured' => ProviderStatus::configured(),
        ]);
    }

    public function index(ListConversationsRequest $request): AnonymousResourceCollection
    {
        $query = AskErpConversation::query()
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $term = trim((string) $request->validated('q', ''));
        if ($term !== '') {
            $query->where('title', 'like', '%'.$term.'%');
        }

        return ConversationResource::collection(
            $query->paginate((int) $request->validated('per_page', 20))->withQueryString(),
        );
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $title = trim((string) $request->validated('title', '')) ?: self::UNTITLED;
        $conversation = AskErpConversation::create([
            'user_id' => $request->user()->id,
            'title' => Str::limit($title, 120, ''),
        ]);

        return (new ConversationResource($conversation->load('messages')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): ConversationResource
    {
        return new ConversationResource($this->own($request, $id)->load('messages'));
    }

    public function ask(AskQuestionRequest $request, int $id): JsonResponse
    {
        $conversation = $this->own($request, $id);
        $question = trim((string) $request->validated('question'));

        if ($conversation->title === self::UNTITLED) {
            $conversation->update(['title' => Str::limit($question, 120, '')]);
        }

        try {
            $message = $this->service->ask($request->user(), $conversation, $question);
        } catch (AskErpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => new MessageResource($message), 'result' => $message->result]);
    }

    private function own(Request $request, int $id): AskErpConversation
    {
        return AskErpConversation::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
