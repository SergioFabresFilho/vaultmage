<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Deck;
use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private AiChatService $chatService) {}

    // GET /api/chat/conversations
    public function indexConversations(Request $request): JsonResponse
    {
        $conversations = Conversation::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'created_at', 'updated_at']);

        return response()->json($conversations);
    }

    // POST /api/chat/conversations
    public function storeConversation(Request $request): JsonResponse
    {
        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'title'   => null,
        ]);

        return response()->json($conversation, 201);
    }

    // GET /api/chat/conversations/{conversation}
    public function showConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $conversation->load('messages');

        return response()->json($conversation);
    }

    // POST /api/chat/conversations/{conversation}/messages
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $request->validate(['message' => 'required|string|max:2000']);

        $result = $this->chatService->chat(
            $conversation,
            $request->user(),
            $request->input('message'),
        );

        return response()->json([
            'message'       => $result['message'],
            'deck_proposal' => $result['deck_proposal'],
        ]);
    }

    // POST /api/chat/conversations/{conversation}/create-deck
    public function createDeck(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $request->validate([
            'deck_name'        => 'required|string|max:255',
            'format'           => 'nullable|string|max:50',
            'strategy_summary' => 'nullable|string|max:500',
            'cards'            => 'required|array|min:1',
            'cards.*.card_id'  => 'required|integer|exists:cards,id',
            'cards.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $deck = Deck::create([
            'user_id'     => $request->user()->id,
            'name'        => $request->input('deck_name'),
            'format'      => $request->input('format'),
            'description' => $request->input('strategy_summary'),
        ]);

        $syncData = collect($request->input('cards'))
            ->mapWithKeys(fn ($entry) => [
                $entry['card_id'] => ['quantity' => $entry['quantity'], 'is_sideboard' => false],
            ])
            ->all();

        $deck->cards()->sync($syncData);

        return response()->json($deck->loadCount('cards'), 201);
    }

    // -------------------------------------------------------------------------

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
