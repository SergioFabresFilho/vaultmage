<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Conversation;
use App\Models\Deck;
use App\Models\Message;
use App\Models\SampleDeck;
use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    private const MAX_TOOL_ROUNDS = 50;

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

        $toolRounds = 0;

        while (true) {
            $response = $this->callOpenAi($history, $tools);
            $choice   = $response['choices'][0] ?? null;

            if (! $choice) {
                abort(502, 'Empty response from OpenAI.');
            }

            $msg       = $choice['message'];
            $toolCalls = $msg['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                // Final text response — always allowed regardless of round count
                $text = $msg['content'] ?? '';
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $text,
                    'metadata' => $proposal,
                ]);

                return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
            }

            if ($toolRounds >= self::MAX_TOOL_ROUNDS) {
                Log::warning('AiChatService: MAX_TOOL_ROUNDS reached', [
                    'conversation_id' => $conversation->id,
                    'rounds'          => $toolRounds,
                ]);
                abort(502, 'AI exceeded the maximum number of tool-call rounds.');
            }

            $toolRounds++;

            $toolNames = array_map(fn ($tc) => $tc['function']['name'], $toolCalls);
            Log::debug('AiChatService: tool round', [
                'conversation_id' => $conversation->id,
                'round'           => $toolRounds,
                'tools'           => $toolNames,
            ]);

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
                $fnName = $call['function']['name'];
                $args   = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];

                Log::debug('AiChatService: executing tool', [
                    'conversation_id' => $conversation->id,
                    'round'           => $toolRounds,
                    'tool'            => $fnName,
                    'args'            => $args,
                ]);

                $result = $this->executeTool($call, $user, $conversation);

                // If propose_deck was called, capture the proposal
                if ($fnName === 'propose_deck' && isset($result['proposal'])) {
                    $proposal = $result['proposal'];
                    $toolOutput = json_encode(['status' => 'proposal_saved', 'card_count' => count($proposal['cards'])]);
                    Log::debug('AiChatService: propose_deck result', [
                        'conversation_id' => $conversation->id,
                        'card_count'      => count($proposal['cards']),
                    ]);
                } else {
                    $toolOutput = json_encode($result);
                    Log::debug('AiChatService: tool result', [
                        'conversation_id' => $conversation->id,
                        'tool'            => $fnName,
                        'result_size'     => strlen($toolOutput),
                    ]);
                }

                $conversation->messages()->create([
                    'role'         => 'tool',
                    'content'      => $toolOutput,
                    'tool_call_id' => $call['id'],
                ]);

                $history[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $toolOutput];
            }
        }
    }

    // -------------------------------------------------------------------------
    // Streaming public API
    // -------------------------------------------------------------------------

    /**
     * Same as chat() but streams the final assistant text via $emit callbacks.
     * Tool rounds execute synchronously; only the final text response is streamed.
     *
     * $emit('token', string $text)  — called for each streamed token
     * $emit('done',  array $data)   — called once with message_id + deck_proposal
     * $emit('error', string $msg)   — called on unrecoverable error
     */
    public function chatStream(Conversation $conversation, User $user, string $userText, callable $emit): void
    {
        if (empty($this->apiKey)) {
            $emit('error', 'OpenAI API key not configured.');
            return;
        }

        $conversation->messages()->create([
            'role'    => 'user',
            'content' => $userText,
        ]);

        if ($conversation->title === null) {
            $conversation->update(['title' => mb_substr($userText, 0, 80)]);
        }

        $history  = $this->buildHistory($conversation);
        $tools    = $this->toolDefinitions();
        $proposal = null;

        $toolRounds = 0;

        while (true) {
            ['text' => $text, 'tool_calls' => $toolCalls] = $this->callOpenAiStreamIter(
                $history,
                $tools,
                fn (string $token) => $emit('token', $token)
            );

            if (empty($toolCalls)) {
                // Final text round — tokens already emitted, persist and signal done
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $text,
                    'metadata' => $proposal,
                ]);

                $emit('done', [
                    'message_id'    => $assistantMsg->id,
                    'deck_proposal' => $proposal,
                ]);
                return;
            }

            if ($toolRounds >= self::MAX_TOOL_ROUNDS) {
                Log::warning('AiChatService: MAX_TOOL_ROUNDS reached (stream)', [
                    'conversation_id' => $conversation->id,
                    'rounds'          => $toolRounds,
                ]);
                $emit('error', 'AI exceeded the maximum number of tool-call rounds.');
                return;
            }

            $toolRounds++;

            $toolNames = array_map(fn ($tc) => $tc['function']['name'], $toolCalls);
            Log::debug('AiChatService: tool round (stream)', [
                'conversation_id' => $conversation->id,
                'round'           => $toolRounds,
                'tools'           => $toolNames,
            ]);

            $emit('thinking', ['round' => $toolRounds, 'tools' => $toolNames]);

            // Tool round — persist assistant turn with tool calls
            $conversation->messages()->create([
                'role'       => 'assistant',
                'content'    => $text ?: null,
                'tool_calls' => $toolCalls,
            ]);

            $history[] = ['role' => 'assistant', 'content' => $text ?: null, 'tool_calls' => $toolCalls];

            foreach ($toolCalls as $call) {
                $fnName = $call['function']['name'];
                $args   = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];

                Log::debug('AiChatService: executing tool (stream)', [
                    'conversation_id' => $conversation->id,
                    'round'           => $toolRounds,
                    'tool'            => $fnName,
                    'args'            => $args,
                ]);

                $result = $this->executeTool($call, $user, $conversation);

                if ($fnName === 'propose_deck' && isset($result['proposal'])) {
                    $proposal    = $result['proposal'];
                    $toolOutput  = json_encode(['status' => 'proposal_saved', 'card_count' => count($proposal['cards'])]);
                    Log::debug('AiChatService: propose_deck result (stream)', [
                        'conversation_id' => $conversation->id,
                        'card_count'      => count($proposal['cards']),
                    ]);
                } else {
                    $toolOutput = json_encode($result);
                    Log::debug('AiChatService: tool result (stream)', [
                        'conversation_id' => $conversation->id,
                        'tool'            => $fnName,
                        'result_size'     => strlen($toolOutput),
                    ]);
                }

                $conversation->messages()->create([
                    'role'         => 'tool',
                    'content'      => $toolOutput,
                    'tool_call_id' => $call['id'],
                ]);

                $history[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $toolOutput];
            }
        }
    }

    // -------------------------------------------------------------------------
    // OpenAI HTTP call (non-streaming)
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

    /**
     * Streaming OpenAI call. Calls $onToken for each text delta as it arrives.
     * Returns the accumulated text and any tool calls found in the response.
     *
     * @return array{text: string, tool_calls: array}
     */
    private function callOpenAiStreamIter(array $messages, array $tools, callable $onToken): array
    {
        $timeout = (int) config('services.openai.timeout', 60);
        $client  = new GuzzleClient();

        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'       => $this->model,
                'messages'    => $messages,
                'tools'       => $tools,
                'tool_choice' => 'auto',
                'stream'      => true,
            ],
            'stream'          => true,
            'timeout'         => $timeout,
            'connect_timeout' => 10,
        ]);

        $body            = $response->getBody();
        $buffer          = '';
        $fullText        = '';
        $toolCallsAccum  = [];
        $done            = false;

        while (! $body->eof() && ! $done) {
            $chunk = $body->read(512);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = trim($line);

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    $done = true;
                    break;
                }

                $parsed = json_decode($data, true);
                if (! $parsed) {
                    continue;
                }

                $delta = $parsed['choices'][0]['delta'] ?? [];

                if (isset($delta['content']) && $delta['content'] !== null && $delta['content'] !== '') {
                    $fullText .= $delta['content'];
                    $onToken($delta['content']);
                }

                if (! empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'];
                        if (! isset($toolCallsAccum[$idx])) {
                            $toolCallsAccum[$idx] = [
                                'id'       => '',
                                'type'     => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (! empty($tc['id'])) {
                            $toolCallsAccum[$idx]['id'] = $tc['id'];
                        }
                        if (isset($tc['function']['name'])) {
                            $toolCallsAccum[$idx]['function']['name'] .= $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCallsAccum[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }
        }

        ksort($toolCallsAccum);

        return ['text' => $fullText, 'tool_calls' => array_values($toolCallsAccum)];
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
            'get_collection'       => $this->toolGetCollection($user, $args),
            'get_decks'            => $this->toolGetDecks($user),
            'search_cards'         => $this->toolSearchCards($args),
            'search_scryfall'      => $this->toolSearchScryfall($args),
            'get_commander_guide'  => $this->toolGetCommanderGuide($args),
            'propose_deck'         => $this->toolProposeDeck($args, $user),
            default                => ['error' => "Unknown tool: {$name}"],
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
            'price_usd'      => $card->price_usd,
            'quantity_owned' => $card->pivot->quantity,
        ]);

        return ['cards' => $cards->values()->all(), 'total' => $cards->count()];
    }

    private function toolGetDecks(User $user): array
    {
        $decks = Deck::where('user_id', $user->id)
            ->withSum('cards as cards_sum_quantity', 'deck_cards.quantity')
            ->get()
            ->map(fn ($deck) => [
                'id'          => $deck->id,
                'name'        => $deck->name,
                'format'      => $deck->format,
                'description' => $deck->description,
                'cards_count' => $deck->cards_sum_quantity ?? 0,
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
            'price_usd'      => $card->price_usd,
        ]);

        return ['cards' => $cards->values()->all()];
    }

    private function toolSearchScryfall(array $args): array
    {
        $query = trim($args['query'] ?? '');
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $scryfall = app(ScryfallService::class);

        try {
            $results = $scryfall->search($query);
        } catch (\Throwable $e) {
            Log::warning('AiChatService: Scryfall search failed', ['query' => $query, 'error' => $e->getMessage()]);
            return ['cards' => [], 'error' => $e->getMessage()];
        }

        $cards = array_slice(
            array_map(fn ($card) => [
                'name'           => $card['name'],
                'type_line'      => $card['type_line'],
                'mana_cost'      => $card['mana_cost'],
                'cmc'            => $card['cmc'],
                'color_identity' => $card['color_identity'],
                'oracle_text'    => $card['oracle_text'] ? mb_substr($card['oracle_text'], 0, 150) : null,
                'rarity'         => $card['rarity'],
                'price_usd'      => $card['price_usd'] ?? null,
                'legalities'     => $card['legalities'],
            ], $results),
            0,
            20
        );

        return ['cards' => $cards, 'total' => count($results)];
    }

    private function toolGetCommanderGuide(array $args): array
    {
        $commanderName = trim($args['commander_name'] ?? '');
        if ($commanderName === '') {
            return ['error' => 'commander_name is required'];
        }

        // Try exact match first, then fuzzy LIKE
        $sample = SampleDeck::where('commander_name', $commanderName)->first()
            ?? SampleDeck::where('commander_name', 'like', '%' . $commanderName . '%')->first();

        if (! $sample) {
            return [
                'found'    => false,
                'message'  => "No sample deck found for commander \"{$commanderName}\". Build from your own knowledge.",
            ];
        }

        return [
            'found'          => true,
            'commander_name' => $sample->commander_name,
            'source'         => 'EDHREC average deck (human-curated)',
            'fetched_at'     => $sample->fetched_at?->toDateString(),
            'total_cards'    => collect($sample->cards)->sum('quantity'),
            'cards'          => $sample->cards,
        ];
    }

    private function toolProposeDeck(array $args, User $user): array
    {
        $scryfall = app(ScryfallService::class);
        $entries  = collect($args['cards'] ?? []);

        // Resolve each card name to a DB record, fetching from Scryfall if needed
        $unresolvedNames = [];
        $resolvedCards = $entries->map(function ($entry) use ($scryfall, &$unresolvedNames) {
            $name = trim($entry['card_name'] ?? '');
            if ($name === '') {
                return null;
            }

            // Reject names that look like category descriptions rather than real card names.
            // Real card names never contain "e.g.", parenthetical examples, or common generic phrases.
            $lowerName = strtolower($name);
            $isCategory = str_contains($name, '(e.g.')
                || str_contains($name, '(e.g.,')
                || preg_match('/\be\.g\b/i', $name)
                || preg_match('/^(discard|removal|counter|ramp|draw|fetch|shock|basic|dual|utility|other)\s+(spell|land|card|creature|artifact|enchantment)/i', $lowerName);

            if ($isCategory) {
                Log::warning('AiChatService: propose_deck received a category name instead of a real card name — skipping', ['name' => $name]);
                $unresolvedNames[] = $name;
                return null;
            }

            // 1. Try local DB first (case-insensitive exact match)
            $card = Card::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

            if (! $card) {
                // 2. Fetch from Scryfall and upsert
                try {
                    $data = $scryfall->findCard($name);
                    $card = Card::updateOrCreate(
                        ['scryfall_id' => $data['scryfall_id']],
                        $data
                    );
                } catch (\Throwable $e) {
                    Log::warning('AiChatService: could not resolve card from Scryfall', ['name' => $name, 'error' => $e->getMessage()]);
                    $unresolvedNames[] = $name;
                    return null;
                }
            }

            return ['card' => $card, 'entry' => $entry];
        })->filter()->values();

        // Build owned quantity map from resolved IDs
        $resolvedIds = $resolvedCards->pluck('card.id')->all();
        $ownedMap = $user->collection()
            ->withPivot('quantity')
            ->whereIn('cards.id', $resolvedIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($c) => $c->pivot->quantity);

        // Merge duplicate card entries (same card_id) by summing quantities.
        // The AI occasionally submits the same card twice (e.g. "Island ×35" and
        // "Island ×5") which would pass a 100-card count check here but then get
        // clobbered by the unique constraint in deck_cards on insert.
        $merged = [];
        foreach ($resolvedCards as $item) {
            $card  = $item['card'];
            $entry = $item['entry'];
            $id    = $card->id;
            $qty   = max(1, (int) ($entry['quantity'] ?? 1));

            if (isset($merged[$id])) {
                $merged[$id]['quantity'] += $qty;
            } else {
                $merged[$id] = [
                    'card_id'        => $id,
                    'name'           => $card->name,
                    'type_line'      => $card->type_line,
                    'mana_cost'      => $card->mana_cost,
                    'image_uri'      => $card->image_uri,
                    'quantity'       => $qty,
                    'owned_quantity' => $ownedMap[$id] ?? 0,
                    'role'           => $entry['role'] ?? null,
                    'reason'         => $entry['reason'] ?? null,
                ];
            }
        }
        $cards = array_values($merged);

        $format     = strtolower($args['format'] ?? '');
        $totalCards = array_sum(array_column($cards, 'quantity'));

        $formatRequirements = [
            'commander' => ['exact' => 100, 'max_copies' => 1, 'max_lands' => 45, 'max_basic_single' => 35],
            'edh'       => ['exact' => 100, 'max_copies' => 1, 'max_lands' => 45, 'max_basic_single' => 35],
            'brawl'     => ['exact' => 60,  'max_copies' => 1, 'max_lands' => 30, 'max_basic_single' => 20],
            'standard'  => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'modern'    => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'pioneer'   => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'legacy'    => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'vintage'   => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'pauper'    => ['min'   => 60,  'max_copies' => 4, 'max_lands' => 30, 'max_basic_single' => 20],
            'draft'     => ['min'   => 40,                     'max_lands' => 25, 'max_basic_single' => 15],
            'sealed'    => ['min'   => 40,                     'max_lands' => 25, 'max_basic_single' => 15],
        ];

        $unresolvedHint = ! empty($unresolvedNames)
            ? ' These card names could not be found and were dropped — replace them with real card names: ' . implode(', ', $unresolvedNames) . '.'
            : '';

        // Compact resolved list for feedback — just name + quantity so the AI can
        // see exactly what was counted and make surgical edits rather than rebuilding.
        $resolvedSummary = implode(', ', array_map(
            fn ($c) => "{$c['name']} ×{$c['quantity']}",
            $cards
        ));

        $req = $formatRequirements[$format] ?? null;
        if ($req) {
            if (isset($req['exact']) && $totalCards !== $req['exact']) {
                $diff = $req['exact'] - $totalCards;
                $action = $diff > 0
                    ? "You are SHORT by {$diff}. Add exactly {$diff} more card(s) to the list below."
                    : 'You are OVER by ' . abs($diff) . '. Remove exactly ' . abs($diff) . ' card(s) from the list below.';
                Log::warning('AiChatService: propose_deck rejected — wrong card count', [
                    'format' => $format, 'required' => $req['exact'], 'submitted' => $totalCards,
                    'unresolved' => $unresolvedNames,
                ]);
                return ['error' => "Deck rejected: {$format} requires exactly {$req['exact']} cards. {$totalCards} resolved.{$unresolvedHint} {$action} Do NOT change any other cards — only add or remove the exact difference. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list."];
            }

            if (isset($req['min']) && $totalCards < $req['min']) {
                $diff = $req['min'] - $totalCards;
                Log::warning('AiChatService: propose_deck rejected — too few cards', [
                    'format' => $format, 'minimum' => $req['min'], 'submitted' => $totalCards,
                    'unresolved' => $unresolvedNames,
                ]);
                return ['error' => "Deck rejected: {$format} requires at least {$req['min']} cards. {$totalCards} resolved.{$unresolvedHint} Add exactly {$diff} more card(s). Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list."];
            }

            if (isset($req['max_copies'])) {
                $maxCopies  = $req['max_copies'];
                $violations = [];

                foreach ($cards as $card) {
                    $isBasicLand = str_contains($card['type_line'] ?? '', 'Basic Land');
                    if (! $isBasicLand && $card['quantity'] > $maxCopies) {
                        $violations[] = "{$card['name']} (×{$card['quantity']}, max {$maxCopies})";
                    }
                }

                if (! empty($violations)) {
                    Log::warning('AiChatService: propose_deck rejected — copy limit exceeded', [
                        'format' => $format, 'max_copies' => $maxCopies, 'violations' => $violations,
                    ]);
                    $list = implode(', ', $violations);
                    return ['error' => "Deck rejected: {$format} allows at most {$maxCopies} cop" . ($maxCopies === 1 ? 'y' : 'ies') . " of each non-basic land card. Fix these cards: {$list}. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list."];
                }
            }

            // Land count validation
            if (isset($req['max_lands'])) {
                $maxLands   = $req['max_lands'];
                $basicNames = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes',
                               'Snow-Covered Plains', 'Snow-Covered Island', 'Snow-Covered Swamp',
                               'Snow-Covered Mountain', 'Snow-Covered Forest'];
                $totalLands    = 0;
                $basicCounts   = [];

                foreach ($cards as $card) {
                    if (str_contains($card['type_line'] ?? '', 'Land')) {
                        $totalLands += $card['quantity'];
                        if (in_array($card['name'], $basicNames, true)) {
                            $basicCounts[$card['name']] = ($basicCounts[$card['name']] ?? 0) + $card['quantity'];
                        }
                    }
                }

                if ($totalLands > $maxLands) {
                    $excess = $totalLands - $maxLands;
                    Log::warning('AiChatService: propose_deck rejected — too many lands', [
                        'format' => $format, 'max_lands' => $maxLands, 'submitted' => $totalLands,
                    ]);
                    return ['error' => "Deck rejected: too many lands. {$format} should have at most {$maxLands} lands but you submitted {$totalLands}. Remove {$excess} land(s) and replace them with spells. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the corrected list."];
                }

                if (isset($req['max_basic_single'])) {
                    $maxBasicSingle = $req['max_basic_single'];
                    foreach ($basicCounts as $basicName => $qty) {
                        if ($qty > $maxBasicSingle) {
                            $excess = $qty - $maxBasicSingle;
                            Log::warning('AiChatService: propose_deck rejected — too many of a single basic land', [
                                'format' => $format, 'land' => $basicName, 'quantity' => $qty, 'max' => $maxBasicSingle,
                            ]);
                            return ['error' => "Deck rejected: too many copies of \"{$basicName}\" ({$qty}). Max allowed is {$maxBasicSingle} of any single basic land type. Remove {$excess} cop" . ($excess === 1 ? 'y' : 'ies') . " and replace with spells or other land types. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the corrected list."];
                        }
                    }
                }
            }
        }

        // Create a draft deck immediately so it is persisted even if the network
        // drops before the user taps "Save". They can validate or discard later.
        $draft = Deck::create([
            'user_id'     => $user->id,
            'name'        => $args['deck_name'] ?? 'New Deck',
            'format'      => $format ?: null,
            'description' => $args['strategy_summary'] ?? null,
            'is_draft'    => true,
        ]);

        $syncData = collect($cards)
            ->mapWithKeys(fn ($c) => [$c['card_id'] => ['quantity' => $c['quantity'], 'is_sideboard' => false]])
            ->all();
        $draft->cards()->sync($syncData);

        Log::debug('AiChatService: draft deck created', [
            'deck_id'  => $draft->id,
            'user_id'  => $user->id,
            'cards'    => count($cards),
        ]);

        return [
            'proposal' => [
                'deck_name'        => $args['deck_name'] ?? 'New Deck',
                'format'           => $args['format'] ?? null,
                'strategy_summary' => $args['strategy_summary'] ?? null,
                'cards'            => $cards,
                'draft_deck_id'    => $draft->id,
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
                    'name'        => 'search_scryfall',
                    'description' => 'Search the full MTG card database via Scryfall. Use this when the user mentions a specific card that is not in their collection or the local database, or when you need card details (rules text, legalities, mana cost) for any card by name. Accepts Scryfall search syntax (e.g. "name:\"Force of Will\"", "c:u t:instant", "o:\"draw a card\"") or plain card names.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Scryfall search query or plain card name.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_commander_guide',
                    'description' => 'Look up an EDHREC average deck for a specific Commander. Returns the card list that experienced human players most commonly include when building around that commander. Use this as a reference for card selection and ratios before building.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'commander_name' => [
                                'type'        => 'string',
                                'description' => 'Full name of the commander card, exactly as printed (e.g. "Atraxa, Praetors\' Voice").',
                            ],
                        ],
                        'required' => ['commander_name'],
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
                                        'card_name' => ['type' => 'string', 'description' => 'Exact card name as printed (e.g. "Sol Ring", "Counterspell").'],
                                        'quantity'  => ['type' => 'integer', 'minimum' => 1],
                                        'role'      => ['type' => 'string', 'description' => 'Role in the deck, e.g. commander, ramp, draw, removal, win-con, land.'],
                                        'reason'    => ['type' => 'string', 'description' => 'Short reason this card is included.'],
                                    ],
                                    'required' => ['card_name', 'quantity'],
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

Deck size and copy limits (you MUST meet these exactly before calling propose_deck):
- Commander / EDH: exactly 100 cards. The commander card MUST be included in the `cards` array (with role "commander") — it counts as 1 of the 100. SINGLETON: max 1 copy of every non-basic land card. Basic lands (Plains, Island, Swamp, Mountain, Forest, Wastes, Snow-Covered basics) may appear in any quantity.
- Brawl: exactly 60 cards. The commander/brawl commander MUST be included in the `cards` array (with role "commander") — it counts as 1 of the 60. SINGLETON: same singleton rule as Commander.

Color identity rules (STRICTLY enforced — violations make a deck illegal):
- Every card in the deck MUST have a color identity that is a subset of the commander's color identity. No exceptions except for colorless cards (which are always allowed).
- Example: a mono-black commander (color identity [B]) allows only black cards, colorless cards, and colorless/black lands. No white, blue, red, or green cards of any kind.
- Before including any card, verify its color identity matches the commander's colors.

Land count guidelines (HARD LIMITS — propose_deck will be REJECTED if you exceed these):
- Commander / EDH: 36–38 lands total (hard max 45). Aim for 10–20 basic lands; NEVER more than 35 of any single basic land type.
- Brawl: ~24 lands total (hard max 30). NEVER more than 20 of any single basic land type.
- Standard / Modern / Pioneer / Legacy / Vintage / Pauper: 20–26 lands (hard max 30). NEVER more than 20 of any single basic land type.
- Draft / Sealed: ~17 lands (hard max 25). NEVER more than 15 of any single basic land type.
- Use leftover slots for spells, not extra basic lands. A 100-card Commander deck needs ~62 non-land cards.
- Standard / Modern / Pioneer / Legacy / Vintage / Pauper: minimum 60 cards. Max 4 copies of any non-basic land card.
- Draft / Sealed: minimum 40 cards.

CRITICAL — card_name rules for propose_deck:
- Every card_name MUST be a real, specific Magic: The Gathering card name exactly as printed on the card.
  CORRECT: "Lightning Bolt", "Counterspell", "Sol Ring", "Island", "Scalding Tarn", "Steam Vents"
  WRONG: "Discard spells", "Fetch lands", "Fetch lands (e.g., Scalding Tarn)", "Basic lands", "Shock lands", "Other blue spells", "Removal spells"
- NEVER use category names, descriptions, or parenthetical examples as a card_name. If you need fetch lands, name each one individually: "Scalding Tarn", "Flooded Strand", etc. If you need basic lands, list each one: "Island", "Mountain", "Plains", etc.
- If you are unsure of a card's exact name, use search_scryfall to confirm it before including it in propose_deck.

Rules:
- Before doing anything else, check whether the user's message provides clear answers to ALL of the following:
    1. Format (e.g. Commander, Modern, Standard) — required
    2. Playstyle / archetype (e.g. aggro, control, combo, midrange, tokens, ramp) — required
    3. Budget (e.g. "under $50", "no budget", "use only cards I own") — required
  If ANY of these are missing or too vague to act on, ask for them all in a single message before calling any tools. Do not guess or assume defaults — ask the user directly.
- Once you have format, playstyle, and budget, call get_collection immediately and start building.
- For Commander decks: call get_commander_guide with the commander name right after get_collection. Use the returned card list as a reference for what staples and support packages experienced players include. Adapt it to the user's collection and budget — don't copy it blindly.
- Prioritize cards the user owns (they cost $0 extra). Use search_cards to fill gaps from the local database.
- Budget enforcement: every card in get_collection, search_cards, and search_scryfall results includes a price_usd field (USD, null means unknown). When the user gives a budget, track the running total as you select cards. Do not include any card whose price_usd would cause the total to exceed the budget. If a card's price_usd is null, treat it as $0 for budget purposes (it may simply not have a cached price yet). Prefer cheaper alternatives when a card is over budget.
- When the user mentions a specific card they don't own, or when a card is not found locally, use search_scryfall to look it up and get its full details before including it in a proposal.
- Always verify your card count matches the format requirement before calling propose_deck. If you are short, use search_scryfall to find more cards to fill the deck.
- When you have a complete card list that meets the deck size requirement, call propose_deck. Do not describe what you are about to do — just do it.
- Keep conversational text short and direct.
PROMPT;
    }
}
