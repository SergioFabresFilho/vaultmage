<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Deck;
use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    // POST /api/chat/conversations/{conversation}/messages/stream
    public function streamMessage(Request $request, Conversation $conversation): StreamedResponse
    {
        $this->authorizeConversation($request, $conversation);

        $request->validate(['message' => 'required|string|max:2000']);

        $user    = $request->user();
        $message = $request->input('message');

        return response()->stream(function () use ($conversation, $user, $message) {
            // Disable output buffering so chunks reach the client immediately
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Allow long-running deck builds (multiple tool rounds + Scryfall lookups)
            set_time_limit(0);

            // Send an initial heartbeat immediately so the client doesn't time out
            // before the first OpenAI response arrives.
            echo 'data: ' . json_encode(['type' => 'thinking', 'round' => 0, 'tools' => []]) . "\n\n";
            flush();

            $this->chatService->chatStream(
                $conversation,
                $user,
                $message,
                function (string $type, mixed $data) {
                    if ($type === 'token') {
                        echo 'data: ' . json_encode(['type' => 'token', 'text' => $data]) . "\n\n";
                    } elseif ($type === 'thinking') {
                        echo 'data: ' . json_encode(['type' => 'thinking', 'round' => $data['round'], 'tools' => $data['tools']]) . "\n\n";
                    } elseif ($type === 'done') {
                        echo 'data: ' . json_encode([
                            'type'          => 'done',
                            'message_id'    => $data['message_id'],
                            'deck_proposal' => $data['deck_proposal'],
                        ]) . "\n\n";
                    } elseif ($type === 'error') {
                        echo 'data: ' . json_encode(['type' => 'error', 'message' => $data]) . "\n\n";
                    }
                    flush();
                }
            );
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
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

        return response()->json($deck, 201);
    }

    // -------------------------------------------------------------------------

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
