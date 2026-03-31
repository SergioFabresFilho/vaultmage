<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Conversation;
use App\Models\Deck;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class AiChatService
{
    private const MAX_TOOL_ROUNDS = 8;

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model  = config('services.openai.model', 'gpt-4.1-mini');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process a user message in a conversation and return the assistant reply.
     * Persists all messages (user, tool calls, tool results, assistant) to DB.
     *
     * @return array{message: Message, deck_proposal: array|null}
     */
    public function chat(Conversation $conversation, User $user, string $userText): array
    {
        if (empty($this->apiKey)) {
            abort(503, 'OpenAI API key not configured.');
        }

        // Persist user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => $userText,
        ]);

        // Auto-title from first user message
        if ($conversation->title === null) {
            $conversation->update(['title' => mb_substr($userText, 0, 80)]);
        }

        // Build OpenAI message history
        $history  = $this->buildHistory($conversation);
        $tools    = $this->toolDefinitions();
        $proposal = null;

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->callOpenAi($history, $tools);
            $choice   = $response['choices'][0] ?? null;

            if (! $choice) {
                abort(502, 'Empty response from OpenAI.');
            }

            $msg       = $choice['message'];
            $toolCalls = $msg['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                // Final text response
                $text = $msg['content'] ?? '';
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $text,
                    'metadata' => $proposal,
                ]);

                return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
            }

            // Persist assistant turn with pending tool calls
            $conversation->messages()->create([
                'role'       => 'assistant',
                'content'    => $msg['content'] ?? null,
                'tool_calls' => $toolCalls,
            ]);

            // Append assistant turn to history once (before any tool results)
            $history[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $toolCalls];

            // Execute each tool and append results
            foreach ($toolCalls as $call) {
                $result = $this->executeTool($call, $user, $conversation);

                // If propose_deck was called, capture the proposal
                if ($call['function']['name'] === 'propose_deck' && isset($result['proposal'])) {
                    $proposal = $result['proposal'];
                    $toolOutput = json_encode(['status' => 'proposal_saved', 'card_count' => count($proposal['cards'])]);
                } else {
                    $toolOutput = json_encode($result);
                }

                $conversation->messages()->create([
                    'role'         => 'tool',
                    'content'      => $toolOutput,
                    'tool_call_id' => $call['id'],
                ]);

                $history[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $toolOutput];
            }
        }

        abort(502, 'AI did not reach a final response within the allowed rounds.');
    }

    // -------------------------------------------------------------------------
    // OpenAI HTTP call
    // -------------------------------------------------------------------------

    private function callOpenAi(array $messages, array $tools): array
    {
        $timeout = (int) config('services.openai.timeout', 60);

        $response = Http::withToken($this->apiKey)
            ->timeout($timeout)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'tools'       => $tools,
                'tool_choice' => 'auto',
            ]);

        if ($response->failed()) {
            abort(502, 'OpenAI request failed: ' . $response->body());
        }

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // History builder
    // -------------------------------------------------------------------------

    private function buildHistory(Conversation $conversation): array
    {
        $history = [[
            'role'    => 'system',
            'content' => $this->systemPrompt(),
        ]];

        foreach ($conversation->messages as $msg) {
            if ($msg->role === 'user') {
                $history[] = ['role' => 'user', 'content' => $msg->content];
            } elseif ($msg->role === 'assistant') {
                $entry = ['role' => 'assistant', 'content' => $msg->content];
                if ($msg->tool_calls) {
                    $entry['tool_calls'] = $msg->tool_calls;
                }
                $history[] = $entry;
            } elseif ($msg->role === 'tool') {
                $history[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $msg->tool_call_id,
                    'content'      => $msg->content,
                ];
            }
        }

        return $history;
    }

    // -------------------------------------------------------------------------
    // Tool execution
    // -------------------------------------------------------------------------

    private function executeTool(array $call, User $user, Conversation $conversation): array
    {
        $name = $call['function']['name'];
        $args = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];

        return match ($name) {
            'get_collection'  => $this->toolGetCollection($user, $args),
            'get_decks'       => $this->toolGetDecks($user),
            'search_cards'    => $this->toolSearchCards($args),
            'propose_deck'    => $this->toolProposeDeck($args, $user),
            default           => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function toolGetCollection(User $user, array $args): array
    {
        $query = $user->collection()
            ->withPivot(['quantity', 'foil'])
            ->with([]);

        if (! empty($args['colors'])) {
            $colors = $args['colors'];
            $query->where(function ($q) use ($colors) {
                foreach ($colors as $color) {
                    $q->orWhereJsonContains('color_identity', $color);
                }
            });
        }

        if (! empty($args['format'])) {
            $format = $args['format'];
            $query->where("legalities->{$format}", 'legal');
        }

        $cards = $query->get()->map(fn ($card) => [
            'id'             => $card->id,
            'name'           => $card->name,
            'type_line'      => $card->type_line,
            'mana_cost'      => $card->mana_cost,
            'cmc'            => $card->cmc,
            'color_identity' => $card->color_identity,
            'oracle_text'    => $card->oracle_text ? mb_substr($card->oracle_text, 0, 120) : null,
            'quantity_owned' => $card->pivot->quantity,
        ]);

        return ['cards' => $cards->values()->all(), 'total' => $cards->count()];
    }

    private function toolGetDecks(User $user): array
    {
        $decks = Deck::where('user_id', $user->id)
            ->withCount('cards')
            ->get()
            ->map(fn ($deck) => [
                'id'          => $deck->id,
                'name'        => $deck->name,
                'format'      => $deck->format,
                'description' => $deck->description,
                'cards_count' => $deck->cards_count,
            ]);

        return ['decks' => $decks->values()->all()];
    }

    private function toolSearchCards(array $args): array
    {
        $query = Card::query();

        if (! empty($args['query'])) {
            $term = $args['query'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('oracle_text', 'like', "%{$term}%")
                  ->orWhere('type_line', 'like', "%{$term}%");
            });
        }

        if (! empty($args['colors'])) {
            foreach ($args['colors'] as $color) {
                $query->whereJsonContains('color_identity', $color);
            }
        }

        if (! empty($args['format'])) {
            $format = $args['format'];
            $query->where("legalities->{$format}", 'legal');
        }

        $cards = $query->limit(30)->get()->map(fn ($card) => [
            'id'             => $card->id,
            'name'           => $card->name,
            'type_line'      => $card->type_line,
            'mana_cost'      => $card->mana_cost,
            'cmc'            => $card->cmc,
            'color_identity' => $card->color_identity,
            'oracle_text'    => $card->oracle_text ? mb_substr($card->oracle_text, 0, 120) : null,
        ]);

        return ['cards' => $cards->values()->all()];
    }

    private function toolProposeDeck(array $args, User $user): array
    {
        $cardIds = collect($args['cards'] ?? [])->pluck('card_id')->unique()->all();
        $dbCards = Card::whereIn('id', $cardIds)->get()->keyBy('id');

        // Merge owned quantities into the proposal
        $ownedMap = $user->collection()
            ->withPivot('quantity')
            ->whereIn('cards.id', $cardIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($c) => $c->pivot->quantity);

        $cards = collect($args['cards'] ?? [])->map(function ($entry) use ($dbCards, $ownedMap) {
            $card = $dbCards[$entry['card_id']] ?? null;
            return [
                'card_id'        => $entry['card_id'],
                'name'           => $card?->name,
                'type_line'      => $card?->type_line,
                'mana_cost'      => $card?->mana_cost,
                'image_uri'      => $card?->image_uri,
                'quantity'       => $entry['quantity'] ?? 1,
                'owned_quantity' => $ownedMap[$entry['card_id']] ?? 0,
                'role'           => $entry['role'] ?? null,
                'reason'         => $entry['reason'] ?? null,
            ];
        })->values()->all();

        return [
            'proposal' => [
                'deck_name'        => $args['deck_name'] ?? 'New Deck',
                'format'           => $args['format'] ?? null,
                'strategy_summary' => $args['strategy_summary'] ?? null,
                'cards'            => $cards,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Tool definitions (OpenAI function schema)
    // -------------------------------------------------------------------------

    private function toolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_collection',
                    'description' => "Retrieve the user's card collection. Optionally filter by colors (W/U/B/R/G) and/or format legality.",
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'colors' => [
                                'type'  => 'array',
                                'items' => ['type' => 'string', 'enum' => ['W', 'U', 'B', 'R', 'G']],
                                'description' => 'Filter cards that contain any of these colors in their color identity.',
                            ],
                            'format' => [
                                'type'        => 'string',
                                'description' => 'Filter cards legal in this format (e.g. commander, modern, standard).',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_decks',
                    'description' => "List the user's existing decks with card counts.",
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_cards',
                    'description' => 'Search all cards in the database by name, oracle text, or type. Use this to look up specific cards or find cards that fit a strategy.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Search term matched against card name, oracle text, and type line.',
                            ],
                            'colors' => [
                                'type'  => 'array',
                                'items' => ['type' => 'string', 'enum' => ['W', 'U', 'B', 'R', 'G']],
                                'description' => 'Restrict results to cards within these colors.',
                            ],
                            'format' => [
                                'type'        => 'string',
                                'description' => 'Restrict results to cards legal in this format.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'propose_deck',
                    'description' => 'Present a final deck proposal to the user for confirmation. Call this when you have decided on a complete card list and are ready for the user to review and create the deck.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'deck_name' => [
                                'type'        => 'string',
                                'description' => 'Suggested deck name.',
                            ],
                            'format' => [
                                'type'        => 'string',
                                'description' => 'MTG format (e.g. commander, modern, standard).',
                            ],
                            'strategy_summary' => [
                                'type'        => 'string',
                                'description' => 'One or two sentence summary of the deck strategy.',
                            ],
                            'cards' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'card_id'  => ['type' => 'integer'],
                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        'role'     => ['type' => 'string', 'description' => 'Role in the deck, e.g. commander, ramp, draw, removal, win-con, land.'],
                                        'reason'   => ['type' => 'string', 'description' => 'Short reason this card is included.'],
                                    ],
                                    'required' => ['card_id', 'quantity'],
                                ],
                                'description' => 'Complete card list for the deck.',
                            ],
                        ],
                        'required' => ['deck_name', 'format', 'cards'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are VaultMage, an expert Magic: The Gathering deck building assistant.

Your job is to build decks. When a user describes what they want, start building immediately — do not ask questions first.

Rules:
- On the first message: call get_collection right away, make reasonable assumptions about anything not specified, and propose a deck as fast as possible.
- Only ask a question if you genuinely cannot proceed without the answer (e.g. the user said nothing at all about format or colors and nothing can be inferred). Never ask more than one question at a time.
- Prefer action over clarification. A deck the user can tweak is better than a questionnaire.
- Prioritize cards the user owns. Use search_cards to fill gaps if needed.
- When you have a card list ready, call propose_deck. Do not describe what you are about to do — just do it.
- Keep conversational text short and direct.
PROMPT;
    }
}
