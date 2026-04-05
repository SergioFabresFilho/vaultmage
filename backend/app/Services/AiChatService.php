<?php

namespace App\Services;

use App\Jobs\FetchCommanderAverageDeck;
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
    private const MAX_TOOL_ROUNDS_BUILD = 16;
    private const MAX_DISCOVERY_ROUNDS_BUILD = 12;
    private const MAX_REPAIR_ROUNDS_PER_PROPOSAL = 5;
    private const MAX_TOOL_ROUNDS_IMPROVEMENT = 6;
    private const MAX_IDENTICAL_SEARCH_REPEATS = 2;

    private string $apiKey;
    private string $model;

    public function __construct(private BuyListFormatter $buyListFormatter)
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
        $maxToolRounds = $this->maxToolRoundsForConversation($conversation);
        $loopState = $this->initializeBuildContext($conversation, $this->initialLoopState(), $userText);

        $toolRounds = 0;

        while (true) {
            if ($this->hasExceededRoundBudget($conversation, $loopState, $toolRounds, $maxToolRounds)) {
                Log::warning('AiChatService: MAX_TOOL_ROUNDS reached', [
                    'conversation_id' => $conversation->id,
                    'rounds'          => $toolRounds,
                    'max_rounds'      => $maxToolRounds,
                    'discovery_rounds'=> $loopState['discovery_rounds'] ?? null,
                    'repair_rounds'   => $loopState['repair_rounds_used'] ?? null,
                    'in_repair'       => $loopState['in_repair'] ?? null,
                ]);

                if ($fallback = $this->buildMaxRoundsFallbackMessage($conversation, $loopState)) {
                    $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                    $assistantMsg = $conversation->messages()->create([
                        'role'     => 'assistant',
                        'content'  => $fallback,
                        'metadata' => $proposal,
                    ]);

                    return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
                }

                abort(502, "AI exceeded the maximum number of tool-call rounds ({$maxToolRounds}).");
            }

            try {
                $response = $this->callOpenAi($history, $tools);
            } catch (\Throwable $e) {
                Log::warning('AiChatService: OpenAI call failed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $this->openAiFailureMessage($conversation),
                    'metadata' => $proposal,
                ]);

                return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
            }
            $choice   = $response['choices'][0] ?? null;

            if (! $choice) {
                abort(502, 'Empty response from OpenAI.');
            }

            $msg       = $choice['message'];
            $toolCalls = $msg['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                // Final text response — always allowed regardless of round count
                $text = $msg['content'] ?? '';
                $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $text,
                    'metadata' => $proposal,
                ]);

                return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
            }

            $toolRounds++;
            $loopState = $this->consumeRoundBudget($conversation, $loopState);

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
                $args   = $this->normalizeToolArgs($conversation, $loopState, $fnName, $args);

                Log::debug('AiChatService: executing tool', [
                    'conversation_id' => $conversation->id,
                    'round'           => $toolRounds,
                    'tool'            => $fnName,
                    'args'            => $args,
                ]);

                $blockedResult = $this->blockedRepairToolResult($conversation, $loopState, $fnName);
                $cachedResult = $blockedResult ?? $this->cachedToolResult($conversation, $loopState, $fnName, $args);
                if ($cachedResult !== null) {
                    $result = $cachedResult;
                    Log::debug($blockedResult !== null ? 'AiChatService: blocking repair-mode tool' : 'AiChatService: reusing cached tool result', [
                        'conversation_id' => $conversation->id,
                        'round' => $toolRounds,
                        'tool' => $fnName,
                    ]);
                } else {
                    $result = $this->executeTool($fnName, $args, $user, $conversation);
                }

                if (in_array($fnName, ['propose_deck', 'propose_changes'], true) && isset($result['proposal'])) {
                    $proposal = $result['proposal'];
                    $toolOutput = json_encode([
                        'status' => 'proposal_saved',
                        'proposal_type' => $proposal['proposal_type'] ?? 'deck',
                        'card_count' => count($proposal['cards'] ?? []),
                        'added_count' => count($proposal['added_cards'] ?? []),
                        'removed_count' => count($proposal['removed_cards'] ?? []),
                    ]);
                    Log::debug("AiChatService: {$fnName} result", [
                        'conversation_id' => $conversation->id,
                        'card_count'      => count($proposal['cards'] ?? []),
                        'added_count'     => count($proposal['added_cards'] ?? []),
                        'removed_count'   => count($proposal['removed_cards'] ?? []),
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

                $loopState = $this->recordLoopState($loopState, $fnName, $args, $result);
                if ($fallback = $this->buildLoopFallbackMessage($conversation, $loopState)) {
                    $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                    $assistantMsg = $conversation->messages()->create([
                        'role'     => 'assistant',
                        'content'  => $fallback,
                        'metadata' => $proposal,
                    ]);

                    return ['message' => $assistantMsg, 'deck_proposal' => $proposal];
                }
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
        $maxToolRounds = $this->maxToolRoundsForConversation($conversation);
        $loopState = $this->initializeBuildContext($conversation, $this->initialLoopState(), $userText);

        $toolRounds = 0;

        while (true) {
            if ($this->hasExceededRoundBudget($conversation, $loopState, $toolRounds, $maxToolRounds)) {
                Log::warning('AiChatService: MAX_TOOL_ROUNDS reached (stream)', [
                    'conversation_id' => $conversation->id,
                    'rounds'          => $toolRounds,
                    'max_rounds'      => $maxToolRounds,
                    'discovery_rounds'=> $loopState['discovery_rounds'] ?? null,
                    'repair_rounds'   => $loopState['repair_rounds_used'] ?? null,
                    'in_repair'       => $loopState['in_repair'] ?? null,
                ]);

                if ($fallback = $this->buildMaxRoundsFallbackMessage($conversation, $loopState)) {
                    $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                    $assistantMsg = $conversation->messages()->create([
                        'role'     => 'assistant',
                        'content'  => $fallback,
                        'metadata' => $proposal,
                    ]);

                    $emit('done', [
                        'message_id'    => $assistantMsg->id,
                        'content'       => $assistantMsg->content,
                        'deck_proposal' => $proposal,
                    ]);
                    return;
                }

                $emit('error', "AI exceeded the maximum number of tool-call rounds ({$maxToolRounds}).");
                return;
            }

            try {
                ['text' => $text, 'tool_calls' => $toolCalls] = $this->callOpenAiStreamIter(
                    $history,
                    $tools,
                    fn (string $token) => $emit('token', $token)
                );
            } catch (\Throwable $e) {
                Log::warning('AiChatService: OpenAI stream call failed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $this->openAiFailureMessage($conversation),
                    'metadata' => $proposal,
                ]);

                $emit('done', [
                    'message_id'    => $assistantMsg->id,
                    'content'       => $assistantMsg->content,
                    'deck_proposal' => $proposal,
                ]);
                return;
            }

            if (empty($toolCalls)) {
                // Final text round — tokens already emitted, persist and signal done
                $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                $assistantMsg = $conversation->messages()->create([
                    'role'     => 'assistant',
                    'content'  => $text,
                    'metadata' => $proposal,
                ]);

                $emit('done', [
                    'message_id'    => $assistantMsg->id,
                    'content'       => $assistantMsg->content,
                    'deck_proposal' => $proposal,
                ]);
                return;
            }

            $toolRounds++;
            $loopState = $this->consumeRoundBudget($conversation, $loopState);

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
                $args   = $this->normalizeToolArgs($conversation, $loopState, $fnName, $args);

                Log::debug('AiChatService: executing tool (stream)', [
                    'conversation_id' => $conversation->id,
                    'round'           => $toolRounds,
                    'tool'            => $fnName,
                    'args'            => $args,
                ]);

                $blockedResult = $this->blockedRepairToolResult($conversation, $loopState, $fnName);
                $cachedResult = $blockedResult ?? $this->cachedToolResult($conversation, $loopState, $fnName, $args);
                if ($cachedResult !== null) {
                    $result = $cachedResult;
                    Log::debug($blockedResult !== null ? 'AiChatService: blocking repair-mode tool (stream)' : 'AiChatService: reusing cached tool result (stream)', [
                        'conversation_id' => $conversation->id,
                        'round' => $toolRounds,
                        'tool' => $fnName,
                    ]);
                } else {
                    $result = $this->executeTool($fnName, $args, $user, $conversation);
                }

                if (in_array($fnName, ['propose_deck', 'propose_changes'], true) && isset($result['proposal'])) {
                    $proposal    = $result['proposal'];
                    $toolOutput  = json_encode([
                        'status' => 'proposal_saved',
                        'proposal_type' => $proposal['proposal_type'] ?? 'deck',
                        'card_count' => count($proposal['cards'] ?? []),
                        'added_count' => count($proposal['added_cards'] ?? []),
                        'removed_count' => count($proposal['removed_cards'] ?? []),
                    ]);
                    Log::debug("AiChatService: {$fnName} result (stream)", [
                        'conversation_id' => $conversation->id,
                        'card_count'      => count($proposal['cards'] ?? []),
                        'added_count'     => count($proposal['added_cards'] ?? []),
                        'removed_count'   => count($proposal['removed_cards'] ?? []),
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

                $loopState = $this->recordLoopState($loopState, $fnName, $args, $result);
                if ($fallback = $this->buildLoopFallbackMessage($conversation, $loopState)) {
                    $proposal = $this->finalizeFallbackProposal($proposal, $loopState, $user, $conversation);
                    $assistantMsg = $conversation->messages()->create([
                        'role'     => 'assistant',
                        'content'  => $fallback,
                        'metadata' => $proposal,
                    ]);

                    $emit('done', [
                        'message_id'    => $assistantMsg->id,
                        'content'       => $assistantMsg->content,
                        'deck_proposal' => $proposal,
                    ]);
                    return;
                }
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
            'content' => $this->systemPrompt($conversation),
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

    private function maxToolRoundsForConversation(Conversation $conversation): int
    {
        return $conversation->deck_id
            ? self::MAX_TOOL_ROUNDS_IMPROVEMENT
            : self::MAX_TOOL_ROUNDS_BUILD;
    }

    private function initialLoopState(): array
    {
        return [
            'last_search_signature'       => null,
            'identical_search_repeats'    => 0,
            'search_rounds'               => 0,
            'discovery_rounds'            => 0,
            'repair_rounds_used'          => 0,
            'in_repair'                   => false,
            'consecutive_proposal_errors' => 0,
            'proposal_attempts'           => 0,
            'last_candidate'             => null,
            'last_shortage'               => null,
            'last_format'                 => null,
            'last_error'                  => null,
            'build_search_colors'         => [],
            'build_commander_name'        => null,
            'last_collection_signature'   => null,
            'last_collection_result'      => null,
        ];
    }

    private function initializeBuildContext(Conversation $conversation, array $state, string $userText): array
    {
        if ($conversation->deck_id) {
            // For improvement sessions, lock search colors to the attached deck's color identity
            // so search tools never return off-color cards.
            $deck = \App\Models\Deck::find($conversation->deck_id);
            if ($deck && ! empty($deck->color_identity)) {
                $state['build_search_colors'] = $this->normalizeColorCodes(array_values($deck->color_identity));
            }
            return $state;
        }

        $state['build_search_colors'] = $this->extractSearchColorsFromText($userText);
        $state['build_commander_name'] = $this->extractCommanderNameFromText($userText);

        if ($state['build_search_colors'] === [] && is_string($state['build_commander_name'])) {
            $state['build_search_colors'] = $this->resolveCommanderColorIdentity($state['build_commander_name']);
        }

        return $state;
    }

    private function recordLoopState(array $state, string $toolName, array $args, array $result): array
    {
        if ($toolName === 'propose_deck') {
            $state['proposal_attempts']++;
            if (isset($result['draft_candidate'])) {
                $state['last_candidate'] = $result['draft_candidate'];
            }

            // Lock build_search_colors to the declared color_identity so any
            // subsequent searches (e.g. after a rejection) are always color-filtered.
            $declaredColors = array_values(array_filter(
                array_map('strtoupper', $args['color_identity'] ?? []),
                fn ($c) => in_array($c, ['W', 'U', 'B', 'R', 'G'], true)
            ));
            if ($declaredColors !== [] && $state['build_search_colors'] === []) {
                $state['build_search_colors'] = $declaredColors;
            }

            $error = $result['error'] ?? null;
            if (is_string($error) && str_starts_with($error, 'Deck rejected:')) {
                $state['consecutive_proposal_errors']++;
                $state['last_error'] = $error;
                $state['last_shortage'] = $this->extractShortageFromProposalError($error);
                $state['last_format'] = $args['format'] ?? $state['last_format'];
                $state['in_repair'] = true;
                $state['repair_rounds_used'] = 0;
            } else {
                $state['consecutive_proposal_errors'] = 0;
                $state['last_error'] = null;
                $state['last_shortage'] = null;
                $state['last_format'] = $args['format'] ?? $state['last_format'];
                $state['in_repair'] = false;
                $state['repair_rounds_used'] = 0;
            }

            return $state;
        }

        if ($toolName === 'get_commander_guide') {
            $commanderColors = array_values(array_filter($result['commander']['color_identity'] ?? [], fn ($color) => is_string($color) && $color !== ''));
            if ($commanderColors !== []) {
                $state['build_search_colors'] = $commanderColors;
            }
            if (! empty($args['commander_name'])) {
                $state['build_commander_name'] = $args['commander_name'];
            }

            return $state;
        }

        if ($toolName === 'get_collection') {
            $state['last_collection_signature'] = json_encode($args);
            $state['last_collection_result'] = $result;
        }

        if (in_array($toolName, ['get_collection', 'search_cards', 'search_scryfall'], true)) {
            $state['search_rounds']++;
            $signature = json_encode([$toolName, $args]);
            if ($signature === $state['last_search_signature']) {
                $state['identical_search_repeats']++;
            } else {
                $state['last_search_signature'] = $signature;
                $state['identical_search_repeats'] = 1;
            }

            return $state;
        }

        return $state;
    }

    private function buildLoopFallbackMessage(Conversation $conversation, array $state): ?string
    {
        if ($conversation->deck_id) {
            return null;
        }

        if ($state['consecutive_proposal_errors'] < 2) {
            return null;
        }

        if (($state['identical_search_repeats'] ?? 0) < self::MAX_IDENTICAL_SEARCH_REPEATS) {
            return null;
        }

        $format = $state['last_format'] ?: 'this format';
        $shortage = $state['last_shortage'];
        $shortageText = is_int($shortage)
            ? " It is still {$shortage} card(s) short of a legal list."
            : '';

        return "I’m pausing here because the deck builder is looping on the same search and not converging on a legal {$format} deck.{$shortageText} The next attempt will be more reliable if you narrow it a bit: ask for a core package first, add must-include cards, or tell me to use only cards you own plus a short buy list.";
    }

    private function buildMaxRoundsFallbackMessage(Conversation $conversation, array $state): ?string
    {
        if ($conversation->deck_id) {
            return null;
        }

        $format = $state['last_format'] ?: 'commander';
        $shortage = $state['last_shortage'];
        $shortageText = is_int($shortage)
            ? " The last legal check still had the deck {$shortage} card(s) short."
            : '';

        if (($state['in_repair'] ?? false) === true) {
            return "I stopped before wasting more tool calls. The deck builder found a draft for {$format} but used the repair budget trying to fix validation issues.{$shortageText} The next attempt will be more reliable if you give must-include cards, a stricter budget, or ask for the core package first.";
        }

        if (($state['search_rounds'] ?? 0) >= 5 && ($state['proposal_attempts'] ?? 0) <= 1) {
            return "I stopped before wasting more tool calls. The deck builder spent too many rounds searching instead of locking a final {$format} list.{$shortageText} A more reliable next step is to ask for a tight version first, like a 25-card core package plus lands/ramp ratios, then expand from there.";
        }

        if (($state['proposal_attempts'] ?? 0) >= 1) {
            return "I stopped before wasting more tool calls. The deck builder did not converge on a legal {$format} list within the round limit.{$shortageText} The next attempt will be more reliable if you give must-include cards, a stricter budget, or ask for the core package first.";
        }

        return "I stopped before wasting more tool calls. The deck builder used the round budget gathering candidates for a new {$format} build and needs a narrower target. Ask for a core package first, a lower budget build, or a list that uses only cards you own.";
    }

    private function cachedToolResult(Conversation $conversation, array $loopState, string $toolName, array $args): ?array
    {
        if ($conversation->deck_id || $toolName !== 'get_collection') {
            return null;
        }

        $signature = json_encode($args);
        if ($signature === false || $signature !== ($loopState['last_collection_signature'] ?? null)) {
            return null;
        }

        $cached = $loopState['last_collection_result'] ?? null;

        return is_array($cached) ? $cached : null;
    }

    private function openAiFailureMessage(Conversation $conversation): string
    {
        if ($conversation->deck_id) {
            return 'The assistant hit an upstream AI timeout while working on this deck. Try again.';
        }

        return 'The deck builder hit an upstream AI timeout before it could finish. Try again; if it hangs again, ask for a tighter first pass like a core package.';
    }

    private function materializeDraftCandidate(array $candidate, User $user, Conversation $conversation): array
    {
        $draft = Deck::create([
            'user_id'     => $user->id,
            'name'        => $candidate['deck_name'] ?? 'New Deck',
            'format'      => $candidate['format'] ?? null,
            'description' => $candidate['strategy_summary'] ?? null,
            'is_draft'    => true,
        ]);

        $syncData = collect($candidate['cards'] ?? [])
            ->filter(fn ($c) => ! empty($c['card_id']))
            ->mapWithKeys(fn ($c) => [$c['card_id'] => ['quantity' => $c['quantity'], 'is_sideboard' => false]])
            ->all();

        if ($syncData !== []) {
            $draft->cards()->sync($syncData);
        }

        $changes = $this->buildProposalChanges($conversation, $candidate['cards'] ?? [], $user);

        return [
            'proposal_type'     => 'deck',
            'deck_name'         => $candidate['deck_name'] ?? 'New Deck',
            'format'            => $candidate['format'] ?? null,
            'strategy_summary'  => $candidate['strategy_summary'] ?? null,
            'cards'             => $candidate['cards'] ?? [],
            'added_cards'       => $changes['added_cards'],
            'removed_cards'     => $changes['removed_cards'],
            'draft_deck_id'     => $draft->id,
            'validation_message'=> $candidate['validation_message'] ?? null,
        ];
    }

    private function finalizeFallbackProposal(?array $proposal, array $loopState, User $user, Conversation $conversation): ?array
    {
        if ($proposal !== null) {
            return $proposal;
        }

        $candidate = $loopState['last_candidate'] ?? null;
        if (! is_array($candidate) || empty($candidate['cards'])) {
            return null;
        }

        if (! empty($candidate['validation_message'])) {
            return [
                'proposal_type'      => 'deck',
                'deck_name'          => $candidate['deck_name'] ?? 'New Deck',
                'format'             => $candidate['format'] ?? null,
                'strategy_summary'   => $candidate['strategy_summary'] ?? null,
                'cards'              => $candidate['cards'] ?? [],
                'added_cards'        => [],
                'removed_cards'      => [],
                'draft_deck_id'      => null,
                'validation_message' => $candidate['validation_message'],
            ];
        }

        return $this->materializeDraftCandidate($candidate, $user, $conversation);
    }

    private function hasExceededRoundBudget(Conversation $conversation, array $loopState, int $toolRounds, int $maxToolRounds): bool
    {
        if ($conversation->deck_id) {
            return $toolRounds >= $maxToolRounds;
        }

        if ($toolRounds >= $maxToolRounds) {
            return true;
        }

        if (($loopState['in_repair'] ?? false) === true) {
            return ($loopState['repair_rounds_used'] ?? 0) >= self::MAX_REPAIR_ROUNDS_PER_PROPOSAL;
        }

        return ($loopState['discovery_rounds'] ?? 0) >= self::MAX_DISCOVERY_ROUNDS_BUILD;
    }

    private function consumeRoundBudget(Conversation $conversation, array $loopState): array
    {
        if ($conversation->deck_id) {
            return $loopState;
        }

        if (($loopState['in_repair'] ?? false) === true) {
            $loopState['repair_rounds_used'] = ($loopState['repair_rounds_used'] ?? 0) + 1;
            return $loopState;
        }

        $loopState['discovery_rounds'] = ($loopState['discovery_rounds'] ?? 0) + 1;

        return $loopState;
    }

    private function extractShortageFromProposalError(string $error): ?int
    {
        if (preg_match('/You are SHORT by (\d+)/', $error, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function blockedRepairToolResult(Conversation $conversation, array $loopState, string $toolName): ?array
    {
        if ($conversation->deck_id) {
            return null;
        }

        $shortage = $loopState['last_shortage'] ?? null;
        if (($loopState['in_repair'] ?? false) !== true || ! is_int($shortage) || $shortage <= 0) {
            return null;
        }

        if (! in_array($toolName, ['get_collection', 'search_cards', 'search_scryfall'], true)) {
            return null;
        }

        $resolvedSummary = implode(', ', array_map(
            fn ($card) => sprintf('%s ×%d', $card['name'] ?? 'Unknown Card', (int) ($card['quantity'] ?? 0)),
            $loopState['last_candidate']['cards'] ?? []
        ));

        $colorMap = ['W' => 'Plains', 'U' => 'Island', 'B' => 'Swamp', 'R' => 'Mountain', 'G' => 'Forest'];
        $deckColors = $loopState['last_candidate']['color_identity'] ?? [];
        $suggestedLands = array_values(array_intersect_key($colorMap, array_flip($deckColors)));
        $landHint = $suggestedLands !== []
            ? ' For this deck\'s color identity [' . implode('/', $deckColors) . '] use: ' . implode(', ', $suggestedLands) . '.'
            : ' Use basic lands matching the deck\'s color identity (Plains for W, Island for U, Swamp for B, Mountain for R, Forest for G).';

        return [
            'error' => "Repair mode: the last propose_deck call was short by {$shortage} card(s). Do not do more exploratory searches or repeat get_collection. Add exactly {$shortage} basic land(s) to reach the required count.{$landHint} These always resolve and are always legal. Then call propose_deck again with the complete corrected list. Current resolved list: [{$resolvedSummary}].",
        ];
    }

    // -------------------------------------------------------------------------
    // Tool execution
    // -------------------------------------------------------------------------

    private function executeTool(string $name, array $args, User $user, Conversation $conversation): array
    {
        return match ($name) {
            'get_collection'       => $this->toolGetCollection($user, $args),
            'get_decks'            => $this->toolGetDecks($user),
            'get_active_deck'      => $this->toolGetActiveDeck($conversation, $user),
            'search_cards'         => $this->toolSearchCards($args),
            'search_scryfall'      => $this->toolSearchScryfall($args),
            'get_commander_guide'  => $this->toolGetCommanderGuide($args),
            'propose_deck'         => $this->toolProposeDeck($args, $user, $conversation),
            'propose_changes'      => $this->toolProposeChanges($args, $user, $conversation),
            default                => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function toolGetCollection(User $user, array $args): array
    {
        $query = $user->collection()
            ->withPivot(['quantity', 'foil'])
            ->with([]);

        if (! empty($args['colors'])) {
            $this->applyAllowedColorsToCardQuery($query, $args['colors']);
        }

        if (! empty($args['format'])) {
            $format = $args['format'];
            $query->where("legalities->{$format}", 'legal');
        }

        $cards = $query->get()
            ->groupBy('name')
            ->map(function ($versions) {
                $first = $versions->first();

                return [
                    'id'             => $first->id,
                    'name'           => $first->name,
                    'type_line'      => $first->type_line,
                    'mana_cost'      => $first->mana_cost,
                    'cmc'            => $first->cmc,
                    'color_identity' => $first->color_identity,
                    'oracle_text'    => $first->oracle_text ? mb_substr($first->oracle_text, 0, 120) : null,
                    'price_usd'      => $first->price_usd,
                    'quantity_owned' => $versions->sum(fn ($c) => $c->pivot->quantity),
                ];
            });

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

    private function toolGetActiveDeck(Conversation $conversation, User $user): array
    {
        if (! $conversation->deck_id) {
            return ['active_deck' => null, 'message' => 'No active deck is attached to this conversation.'];
        }

        $deck = Deck::where('user_id', $user->id)
            ->with(['cards' => function ($query) {
                $query->select(
                    'cards.id',
                    'cards.name',
                    'cards.type_line',
                    'cards.mana_cost',
                    'cards.oracle_text',
                    'cards.cmc',
                    'cards.color_identity',
                    'cards.price_usd'
                );
            }])
            ->find($conversation->deck_id);

        if (! $deck) {
            return ['active_deck' => null, 'message' => 'The attached deck could not be found.'];
        }

        $collectionQty = $user->collection()
            ->pluck('collection_cards.quantity', 'cards.id');

        $cards = $deck->cards->map(function ($card) use ($collectionQty) {
            $owned = (int) ($collectionQty[$card->id] ?? 0);
            $required = (int) $card->pivot->quantity;

            return [
                'id'               => $card->id,
                'name'             => $card->name,
                'type_line'        => $card->type_line,
                'mana_cost'        => $card->mana_cost,
                'cmc'              => $card->cmc,
                'color_identity'   => $card->color_identity,
                'oracle_text'      => $card->oracle_text ? mb_substr($card->oracle_text, 0, 160) : null,
                'price_usd'        => $card->price_usd,
                'quantity'         => $required,
                'quantity_owned'   => $owned,
                'quantity_missing' => max(0, $required - $owned),
                'is_sideboard'     => (bool) $card->pivot->is_sideboard,
            ];
        });

        return [
            'active_deck' => [
                'id'             => $deck->id,
                'name'           => $deck->name,
                'format'         => $deck->format,
                'description'    => $deck->description,
                'color_identity' => $deck->color_identity,
                'total_cards'    => $cards->sum('quantity'),
                'cards'          => $cards->values()->all(),
            ],
        ];
    }

    private function toolSearchCards(array $args): array
    {
        $query = Card::query();
        $term = trim((string) ($args['query'] ?? ''));
        $rolePatterns = $this->searchRolePatterns($term);

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('oracle_text', 'like', "%{$term}%")
                  ->orWhere('type_line', 'like', "%{$term}%");
            });

            if ($rolePatterns !== []) {
                $query->orWhere(function ($q) use ($rolePatterns) {
                    foreach ($rolePatterns as $pattern) {
                        $q->orWhere('name', 'like', "%{$pattern}%")
                            ->orWhere('oracle_text', 'like', "%{$pattern}%")
                            ->orWhere('type_line', 'like', "%{$pattern}%");
                    }
                });
            }
        }

        if (! empty($args['colors'])) {
            $this->applyAllowedColorsToCardQuery($query, $args['colors']);
        }

        if (! empty($args['format'])) {
            $format = $args['format'];
            $query->where("legalities->{$format}", 'legal');
        }

        $cards = $query->limit(150)->get()
            ->sort(function (Card $left, Card $right) use ($term, $rolePatterns) {
                $leftSort = [
                    $this->searchCardRank($left, $term, $rolePatterns),
                    $left->price_usd ?? 0.0,
                    $left->cmc ?? 0.0,
                    strtolower($left->name ?? ''),
                ];
                $rightSort = [
                    $this->searchCardRank($right, $term, $rolePatterns),
                    $right->price_usd ?? 0.0,
                    $right->cmc ?? 0.0,
                    strtolower($right->name ?? ''),
                ];

                return $leftSort <=> $rightSort;
            })
            ->take(60)
            ->map(fn ($card) => [
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

    private function searchCardRank(Card $card, string $term, array $rolePatterns): int
    {
        $normalizedTerm = strtolower(trim($term));
        $name = strtolower($card->name ?? '');
        $typeLine = strtolower($card->type_line ?? '');
        $oracleText = strtolower($card->oracle_text ?? '');

        $score = 1000;

        if ($normalizedTerm !== '') {
            if ($name === $normalizedTerm) {
                $score -= 800;
            } elseif (str_starts_with($name, $normalizedTerm)) {
                $score -= 500;
            } elseif (str_contains($name, $normalizedTerm)) {
                $score -= 300;
            } elseif (str_contains($typeLine, $normalizedTerm)) {
                $score -= 150;
            } elseif (str_contains($oracleText, $normalizedTerm)) {
                $score -= 100;
            }
        }

        foreach ($rolePatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                $score -= 120;
            }
            if (str_contains($typeLine, $pattern)) {
                $score -= 80;
            }
            if (str_contains($oracleText, $pattern)) {
                $score -= 60;
            }
        }

        if (str_contains($typeLine, 'land') && preg_match('/\bland\b/i', $term) === 1) {
            $score -= 120;
        }

        return $score;
    }

    private function searchRolePatterns(string $term): array
    {
        $normalized = strtolower(trim($term));
        if ($normalized === '') {
            return [];
        }

        $patterns = [];

        $roleMap = [
            'ramp' => ['add {', 'search your library for a basic land', 'mana of any color', 'treasure token', 'mana rock'],
            'draw' => ['draw a card', 'draw two cards', 'whenever you draw', 'impulse', 'loot'],
            'removal' => ['destroy target', 'exile target', 'deals damage to target', '-x/-x', 'sacrifice target'],
            'interaction' => ['counter target', 'destroy target', 'exile target', 'tap target', 'return target'],
            'counterspell' => ['counter target spell', 'counter target'],
            'cantrip' => ['draw a card', 'scry', 'surveil'],
            'finisher' => ['double strike', 'extra combat', 'each opponent loses', 'cannot be blocked', '+x/+0'],
            'payoff' => ['whenever another', 'whenever you cast', 'create a token', 'creatures you control get'],
            'tokens' => ['create a token', 'create two', 'create three'],
            'haste' => ['haste', 'gains haste'],
            'tempo' => ['return target', 'tap target', 'counter target', 'flash', 'flying'],
            'control' => ['counter target', 'destroy target', 'exile target', 'draw a card'],
        ];

        foreach ($roleMap as $keyword => $mappedPatterns) {
            if (str_contains($normalized, $keyword)) {
                array_push($patterns, ...$mappedPatterns);
            }
        }

        return array_values(array_unique(array_map('strtolower', $patterns)));
    }

    private function toolSearchScryfall(array $args): array
    {
        $allowedColors = $this->normalizeColorCodes($args['colors'] ?? []);
        $query = $this->applyScryfallColorIdentityConstraint(trim($args['query'] ?? ''), $allowedColors);
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
            array_values(array_filter(array_map(fn ($card) => [
                'name'           => $card['name'],
                'type_line'      => $card['type_line'],
                'mana_cost'      => $card['mana_cost'],
                'cmc'            => $card['cmc'],
                'color_identity' => $card['color_identity'],
                'oracle_text'    => $card['oracle_text'] ? mb_substr($card['oracle_text'], 0, 150) : null,
                'rarity'         => $card['rarity'],
                'price_usd'      => $card['price_usd'] ?? null,
                'legalities'     => $card['legalities'],
            ], $results), fn ($card) => $this->cardMatchesAllowedColors($card['color_identity'] ?? [], $allowedColors))),
            0,
            20
        );

        return ['cards' => $cards, 'total' => count($results)];
    }

    private function normalizeToolArgs(Conversation $conversation, array $loopState, string $toolName, array $args): array
    {
        $buildColors = $this->normalizeColorCodes($loopState['build_search_colors'] ?? []);

        if ($buildColors !== [] && in_array($toolName, ['get_collection', 'search_cards', 'search_scryfall'], true)) {
            // Always override colors with the locked build/deck colors so the AI
            // never receives cards outside the deck's color identity.
            $args['colors'] = $buildColors;
        }

        if (! $conversation->deck_id) {
            if ($toolName === 'get_commander_guide' && empty($args['commander_name']) && ! empty($loopState['build_commander_name'])) {
                $args['commander_name'] = $loopState['build_commander_name'];
            }
        }

        return $args;
    }

    private function applyAllowedColorsToCardQuery($query, array $colors): void
    {
        $allowedColors = $this->normalizeColorCodes($colors);
        if ($allowedColors === []) {
            return;
        }

        $disallowedColors = array_values(array_diff(['W', 'U', 'B', 'R', 'G'], $allowedColors));
        foreach ($disallowedColors as $color) {
            $query->whereJsonDoesntContain('color_identity', $color);
        }
    }

    private function applyScryfallColorIdentityConstraint(string $query, array $colors): string
    {
        $query = trim($query);
        if ($query === '' || $colors === []) {
            return $query;
        }

        if (preg_match('/\bid\s*(?:[:<>]=?|=)\s*/i', $query) === 1) {
            return $query;
        }

        $constraint = $colors === ['C']
            ? 'id=0'
            : 'id<=' . strtolower(implode('', array_values(array_diff($colors, ['C']))));

        return trim($query . ' ' . $constraint);
    }

    private function cardMatchesAllowedColors(array $cardColors, array $allowedColors): bool
    {
        $allowedColors = $this->normalizeColorCodes($allowedColors);
        if ($allowedColors === []) {
            return true;
        }

        $cardColors = $this->normalizeColorCodes($cardColors);
        if ($allowedColors === ['C']) {
            return $cardColors === [];
        }

        return array_diff($cardColors, array_diff($allowedColors, ['C'])) === [];
    }

    private function normalizeColorCodes(array $colors): array
    {
        $normalized = [];
        foreach ($colors as $color) {
            if (! is_string($color)) {
                continue;
            }

            $color = strtoupper(trim($color));
            if (in_array($color, ['W', 'U', 'B', 'R', 'G', 'C'], true) && ! in_array($color, $normalized, true)) {
                $normalized[] = $color;
            }
        }

        return $normalized;
    }

    private function extractCommanderNameFromText(string $text): ?string
    {
        if (preg_match('/Commander:\s*([^.\n]+)/i', $text, $matches) !== 1) {
            return null;
        }

        $name = trim($matches[1]);

        return $name !== '' ? $name : null;
    }

    private function extractSearchColorsFromText(string $text): array
    {
        if (preg_match('/Search colors:\s*\[([^\]]+)\]/i', $text, $matches) === 1) {
            return $this->normalizeColorCodes(preg_split('/[\s,\/]+/', $matches[1], -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        if (preg_match('/Colors:\s*([^.\n]+)/i', $text, $matches) !== 1) {
            return [];
        }

        $segment = strtolower(trim($matches[1]));
        if ($segment === 'colorless cards only') {
            return ['C'];
        }

        $map = [
            'white' => 'W',
            'blue' => 'U',
            'black' => 'B',
            'red' => 'R',
            'green' => 'G',
            'colorless' => 'C',
        ];

        $colors = [];
        foreach ($map as $label => $code) {
            if (str_contains($segment, $label)) {
                $colors[] = $code;
            }
        }

        return $this->normalizeColorCodes($colors);
    }

    private function nameToEdhrecSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = str_replace("'", '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function resolveCommanderColorIdentity(string $commanderName): array
    {
        $card = Card::whereRaw('LOWER(name) = ?', [strtolower($commanderName)])->first();

        return $this->normalizeColorCodes($card?->color_identity ?? []);
    }

    private function toolGetCommanderGuide(array $args): array
    {
        $commanderName = trim($args['commander_name'] ?? '');
        if ($commanderName === '') {
            return ['error' => 'commander_name is required'];
        }
        $archetype = trim((string) ($args['archetype'] ?? ''));
        $archetype = $archetype !== '' ? $archetype : null;

        $validTiers = ['budget', 'average', 'expensive'];
        $budgetTier = in_array($args['budget_tier'] ?? '', $validTiers, true)
            ? $args['budget_tier']
            : 'average';

        $commanderCard = Card::whereRaw('LOWER(name) = ?', [strtolower($commanderName)])->first();
        if (! $commanderCard) {
            try {
                $data = app(ScryfallService::class)->findCard($commanderName);
                $commanderCard = Card::updateOrCreate(
                    ['scryfall_id' => $data['scryfall_id']],
                    $data
                );
            } catch (\Throwable $e) {
                Log::warning('AiChatService: could not resolve commander card', [
                    'name' => $commanderName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        [$sample, $sampleMatch] = $this->findCommanderSampleDeck($commanderName, $budgetTier, $archetype);

        // If not cached, fetch from EDHREC inline (synchronous) and re-query.
        if (! $sample && $commanderCard) {
            $slug = $this->nameToEdhrecSlug($commanderCard->name);
            try {
                FetchCommanderAverageDeck::dispatchSync($commanderCard->name, $slug, $budgetTier, $archetype);
                [$sample, $sampleMatch] = $this->findCommanderSampleDeck($commanderCard->name, $budgetTier, $archetype);
            } catch (\Throwable $e) {
                Log::warning('AiChatService: inline EDHREC fetch failed', [
                    'commander'   => $commanderName,
                    'budget_tier' => $budgetTier,
                    'archetype'   => $archetype,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if (! $sample) {
            return [
                'found'       => false,
                'budget_tier' => $budgetTier,
                'requested_archetype' => $archetype,
                'message'     => "No EDHREC deck found for commander \"{$commanderName}\". Build from your own knowledge of this commander.",
                'commander'   => $commanderCard ? [
                    'name'           => $commanderCard->name,
                    'type_line'      => $commanderCard->type_line,
                    'mana_cost'      => $commanderCard->mana_cost,
                    'color_identity' => $commanderCard->color_identity,
                    'oracle_text'    => $commanderCard->oracle_text ? mb_substr($commanderCard->oracle_text, 0, 200) : null,
                ] : null,
            ];
        }

        // Bulk-resolve EDHREC card names from the local DB so the AI gets type,
        // mana cost, price, and color identity for role-matched substitution.
        $sampleCards = collect($sample->cards);
        $cardNames   = $sampleCards->pluck('name')->all();
        $dbCards     = Card::whereIn('name', $cardNames)->get()->keyBy('name');

        $enrichedCards = $sampleCards->map(function ($entry) use ($dbCards) {
            $card = $dbCards->get($entry['name']);
            return [
                'name'           => $entry['name'],
                'quantity'       => $entry['quantity'],
                'type_line'      => $card?->type_line,
                'mana_cost'      => $card?->mana_cost,
                'cmc'            => $card?->cmc,
                'color_identity' => $card?->color_identity,
                'oracle_text'    => $card?->oracle_text ? mb_substr($card->oracle_text, 0, 100) : null,
                'price_usd'      => $card?->price_usd,
            ];
        })->values()->all();

        return [
            'found'          => true,
            'commander_name' => $sample->commander_name,
            'budget_tier'    => $sample->budget_tier,
            'requested_archetype' => $archetype,
            'resolved_archetype' => $sample->archetype,
            'sample_match'   => $sampleMatch,
            'commander'      => $commanderCard ? [
                'name'           => $commanderCard->name,
                'type_line'      => $commanderCard->type_line,
                'mana_cost'      => $commanderCard->mana_cost,
                'color_identity' => $commanderCard->color_identity,
                'oracle_text'    => $commanderCard->oracle_text ? mb_substr($commanderCard->oracle_text, 0, 200) : null,
            ] : null,
            'source'         => 'EDHREC ' . $sample->budget_tier . ' deck (human-curated)',
            'archetype_note' => $archetype
                ? $sample->archetype === $archetype
                    ? "Use this as an exact {$archetype} baseline shell. Preserve the theme when choosing owned-card substitutions."
                    : "Requested archetype {$archetype} was not cached exactly. Use this {$sample->archetype} baseline as the closest fallback shell and preserve the requested theme when choosing substitutions."
                : null,
            'fetched_at'     => $sample->fetched_at?->toDateString(),
            'total_cards'    => $sampleCards->sum('quantity'),
            'cards'          => $enrichedCards,
        ];
    }

    private function findCommanderSampleDeck(string $commanderName, string $budgetTier, ?string $archetype): array
    {
        $normalizedArchetype = $this->normalizeSampleDeckArchetype($archetype);

        $queries = [
            ['budget_tier' => $budgetTier, 'archetype' => $normalizedArchetype, 'match' => $normalizedArchetype === 'generic' ? 'exact_generic' : 'exact_archetype'],
        ];

        if ($normalizedArchetype !== 'generic') {
            $queries[] = ['budget_tier' => 'average', 'archetype' => $normalizedArchetype, 'match' => 'archetype_average_fallback'];
            $queries[] = ['budget_tier' => $budgetTier, 'archetype' => 'generic', 'match' => 'generic_budget_fallback'];
        }

        if ($budgetTier !== 'average' || $normalizedArchetype !== 'generic') {
            $queries[] = ['budget_tier' => 'average', 'archetype' => 'generic', 'match' => 'generic_average_fallback'];
        }

        foreach ($queries as $query) {
            $sample = SampleDeck::where('commander_name', $commanderName)
                    ->where('budget_tier', $query['budget_tier'])
                    ->where('archetype', $query['archetype'])
                    ->first()
                ?? SampleDeck::where('commander_name', 'like', '%' . $commanderName . '%')
                    ->where('budget_tier', $query['budget_tier'])
                    ->where('archetype', $query['archetype'])
                    ->first();

            if ($sample) {
                return [$sample, $query['match']];
            }
        }

        return [null, null];
    }

    private function normalizeSampleDeckArchetype(?string $archetype): string
    {
        $value = strtolower(trim((string) $archetype));
        return $value !== '' ? $value : 'generic';
    }

    private function toolProposeDeck(array $args, User $user, Conversation $conversation): array
    {
        // New deck builds are Commander-only. Improvement sessions (deck_id set) allow any format.
        if (! $conversation->deck_id) {
            $submittedFormat = strtolower(trim($args['format'] ?? ''));
            if (! in_array($submittedFormat, ['commander', 'edh', 'brawl'], true)) {
                $error = 'Deck rejected: new deck building is Commander / EDH only. Set format to "commander" and include a commander card with role "commander".';
                return ['error' => $error, 'draft_candidate' => null];
            }
        }

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
        $merged        = [];
        $mergedNames   = []; // track cards that appeared more than once
        foreach ($resolvedCards as $item) {
            $card  = $item['card'];
            $entry = $item['entry'];
            $id    = $card->id;
            $qty   = max(1, (int) ($entry['quantity'] ?? 1));

            if (isset($merged[$id])) {
                $merged[$id]['quantity'] += $qty;
                $mergedNames[$card->name]  = $merged[$id]['quantity'];
            } else {
                $merged[$id] = [
                    'card_id'        => $id,
                    'name'           => $card->name,
                    'type_line'      => $card->type_line,
                    'mana_cost'      => $card->mana_cost,
                    'color_identity' => $card->color_identity,
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
            'commander' => ['exact' => 100, 'max_copies' => 1, 'max_lands' => 40, 'max_basic_single' => 25],
            'edh'       => ['exact' => 100, 'max_copies' => 1, 'max_lands' => 40, 'max_basic_single' => 25],
            'brawl'     => ['exact' => 60,  'max_copies' => 1, 'max_lands' => 28, 'max_basic_single' => 18],
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

        // Warn the AI when it submitted the same card more than once.
        // This causes the merged quantity to exceed max_copies and leads to a loop
        // where the AI tries to "fix" ×2 without realising it listed the card twice.
        $duplicateHint = ! empty($mergedNames)
            ? ' WARNING: you listed these cards more than once in your submission and their quantities were summed — submit each card only once: '
              . implode(', ', array_map(fn ($n, $q) => "{$n} (merged to ×{$q})", array_keys($mergedNames), $mergedNames)) . '.'
            : '';

        // Compact resolved list for feedback — just name + quantity so the AI can
        // see exactly what was counted and make surgical edits rather than rebuilding.
        $resolvedSummary = implode(', ', array_map(
            fn ($c) => "{$c['name']} ×{$c['quantity']}",
            $cards
        ));

        $declaredColorIdentity = array_values(array_filter(
            array_map('strtoupper', $args['color_identity'] ?? []),
            fn ($c) => in_array($c, ['W', 'U', 'B', 'R', 'G'], true)
        ));

        $draftCandidate = [
            'deck_name'         => $args['deck_name'] ?? 'New Deck',
            'format'            => $args['format'] ?? null,
            'strategy_summary'  => $args['strategy_summary'] ?? null,
            'cards'             => $cards,
            'color_identity'    => $declaredColorIdentity,
            'validation_message'=> null,
        ];

        $req = $formatRequirements[$format] ?? null;
        if ($req) {
            $commanderColors = [];
            if (in_array($format, ['commander', 'edh', 'brawl'], true)) {
                $commanderCards = array_values(array_filter(
                    $cards,
                    fn ($card) => strtolower((string) ($card['role'] ?? '')) === 'commander'
                ));

                if (count($commanderCards) !== 1) {
                    $error = 'Deck rejected: exactly one commander card with role "commander" is required for this format.';
                    $draftCandidate['validation_message'] = $error;
                    return ['error' => $error, 'draft_candidate' => $draftCandidate];
                }

                $commander = $commanderCards[0];
                $commanderModel = collect($resolvedCards)
                    ->first(fn ($item) => $item['card']->id === $commander['card_id']);

                $commanderColors = array_values($commanderModel['card']->color_identity ?? []);
                $offenders = [];

                foreach ($cards as $card) {
                    $cardColors = array_values($card['color_identity'] ?? []);
                    $outsideColors = array_values(array_diff($cardColors, $commanderColors));

                    if ($outsideColors !== []) {
                        $offenders[] = sprintf(
                            '%s [%s]',
                            $card['name'],
                            implode('/', $outsideColors)
                        );
                    }
                }

                if ($offenders !== []) {
                    $list = implode(', ', array_slice($offenders, 0, 12));
                    $error = 'Deck rejected: color identity violation. Commander '
                        . $commander['name']
                        . ' allows only [' . implode('/', $commanderColors ?: ['colorless']) . ']. '
                        . 'These cards are outside that color identity: '
                        . $list . '.';
                    $draftCandidate['validation_message'] = $error;
                    return ['error' => $error, 'draft_candidate' => $draftCandidate];
                }
            }

            // Color identity check for non-commander formats (commander formats use the commander card above).
            // If the AI declared a color_identity for the deck, enforce it server-side.
            if (! in_array($format, ['commander', 'edh', 'brawl'], true)) {
                $declaredColors = array_values(array_filter(
                    array_map('strtoupper', $args['color_identity'] ?? []),
                    fn ($c) => in_array($c, ['W', 'U', 'B', 'R', 'G'], true)
                ));

                if ($declaredColors !== []) {
                    $colorOffenders = [];
                    foreach ($cards as $card) {
                        $cardColors = array_values($card['color_identity'] ?? []);
                        $outsideColors = array_values(array_diff($cardColors, $declaredColors));
                        if ($outsideColors !== []) {
                            $colorOffenders[] = sprintf('%s [%s]', $card['name'], implode('/', $outsideColors));
                        }
                    }

                    if ($colorOffenders !== []) {
                        $list  = implode(', ', array_slice($colorOffenders, 0, 12));
                        $error = 'Deck rejected: color identity violation. This deck is declared as ['
                            . implode('/', $declaredColors)
                            . '] but these cards are outside that color identity: '
                            . $list
                            . '. Remove them and replace with cards whose color identity fits within ['
                            . implode('/', $declaredColors) . '].';
                        Log::warning('AiChatService: propose_deck rejected — non-commander color identity violation', [
                            'format'          => $format,
                            'declared_colors' => $declaredColors,
                            'offenders'       => $colorOffenders,
                        ]);
                        $draftCandidate['validation_message'] = $error;
                        return ['error' => $error, 'draft_candidate' => $draftCandidate];
                    }
                }
            }

            if (isset($req['exact']) && $totalCards !== $req['exact']) {
                if (
                    in_array($format, ['commander', 'edh'], true)
                    && $req['exact'] - $totalCards === 1
                    && $totalCards === 99
                ) {
                    $autoCompleted = $this->autoCompleteCommanderSingleCardShortage(
                        $cards,
                        $commanderColors,
                        $req['max_lands'] ?? 40,
                        $req['max_basic_single'] ?? 25,
                        $scryfall
                    );

                    if ($autoCompleted !== null) {
                        $cards = $autoCompleted;
                        $totalCards = array_sum(array_column($cards, 'quantity'));
                        $draftCandidate['cards'] = $cards;
                    }
                }
            }

            if (isset($req['exact']) && $totalCards !== $req['exact']) {
                $diff = $req['exact'] - $totalCards;
                $action = $diff > 0
                    ? "You are SHORT by {$diff}. Add exactly {$diff} more card(s) to the list below."
                    : 'You are OVER by ' . abs($diff) . '. Remove exactly ' . abs($diff) . ' card(s) from the list below.';
                Log::warning('AiChatService: propose_deck rejected — wrong card count', [
                    'format' => $format, 'required' => $req['exact'], 'submitted' => $totalCards,
                    'unresolved' => $unresolvedNames,
                ]);
                // Build a concrete "add X spells + Y lands" directive so the AI never
                // pads a short deck with pure basic lands.
                $fillDirective = '';
                if ($diff > 0 && isset($req['max_lands'], $req['exact'])) {
                    $currentLands = array_sum(array_map(
                        fn ($c) => str_contains($c['type_line'] ?? '', 'Land') ? $c['quantity'] : 0,
                        $cards
                    ));
                    $targetLands   = 38; // ideal commander land count
                    $landsAllowed  = max(0, $targetLands - $currentLands);
                    $landsToAdd    = min($landsAllowed, $diff);
                    $spellsToAdd   = $diff - $landsToAdd;
                    if ($spellsToAdd > 0 && $landsToAdd > 0) {
                        $fillDirective = " FILL BREAKDOWN: add exactly {$spellsToAdd} spell(s)/creature(s) and {$landsToAdd} basic land(s) of the commander's colors (not more, not fewer). Do NOT add all {$diff} as basic lands.";
                    } elseif ($spellsToAdd > 0) {
                        $fillDirective = " FILL: you already have {$currentLands} lands (target 36–38) — add all {$spellsToAdd} card(s) as spells or creatures, NOT basic lands.";
                    }
                    // else all lands allowed (diff is small and deck is under target) — no directive needed
                }
                $error = "Deck rejected: {$format} requires exactly {$req['exact']} cards. {$totalCards} resolved.{$unresolvedHint}{$duplicateHint}{$fillDirective} {$action} Do NOT change any other cards — only add or remove the exact difference. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list.";
                $draftCandidate['validation_message'] = $error;
                return ['error' => $error, 'draft_candidate' => $draftCandidate];
            }

            if (isset($req['min']) && $totalCards < $req['min']) {
                $diff = $req['min'] - $totalCards;
                Log::warning('AiChatService: propose_deck rejected — too few cards', [
                    'format' => $format, 'minimum' => $req['min'], 'submitted' => $totalCards,
                    'unresolved' => $unresolvedNames,
                ]);
                $error = "Deck rejected: {$format} requires at least {$req['min']} cards. {$totalCards} resolved.{$unresolvedHint}{$duplicateHint} Add exactly {$diff} more card(s). Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list.";
                $draftCandidate['validation_message'] = $error;
                return ['error' => $error, 'draft_candidate' => $draftCandidate];
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
                    // Compute how many excess copies exist so the AI knows how many replacements to add
                    $excessCopies = 0;
                    foreach ($cards as $card) {
                        $isBasicLandForExcess = str_contains($card['type_line'] ?? '', 'Basic Land');
                        if (! $isBasicLandForExcess && $card['quantity'] > $maxCopies) {
                            $excessCopies += $card['quantity'] - $maxCopies;
                        }
                    }
                    $error = "Deck rejected: {$format} allows at most {$maxCopies} cop" . ($maxCopies === 1 ? 'y' : 'ies') . " of each non-basic land card. Fix these cards: {$list}. Reducing those quantities removes {$excessCopies} card(s) from the deck — you MUST add {$excessCopies} different card(s) as replacements to keep the total at {$totalCards}.{$duplicateHint} Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the complete corrected list.";
                    $draftCandidate['validation_message'] = $error;
                    return ['error' => $error, 'draft_candidate' => $draftCandidate];
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
                    $nonLands = $totalCards - $totalLands;
                    $requiredTotal = $req['exact'] ?? $totalCards;
                    $targetLandsMsg = 38;
                    $error = "Deck rejected: too many lands. You submitted {$totalLands} lands but a Commander deck should have 36–38 (hard max {$maxLands}). You only have {$nonLands} non-land cards — a playable deck needs ~62. Remove {$excess} land(s) and add {$excess} spell(s) or creature(s) in their place so the total stays at {$requiredTotal}. Do NOT just delete them. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the corrected list.";
                    $draftCandidate['validation_message'] = $error;
                    return ['error' => $error, 'draft_candidate' => $draftCandidate];
                }

                if (isset($req['max_basic_single'])) {
                    $maxBasicSingle = $req['max_basic_single'];
                    foreach ($basicCounts as $basicName => $qty) {
                        if ($qty > $maxBasicSingle) {
                            $excess = $qty - $maxBasicSingle;
                            Log::warning('AiChatService: propose_deck rejected — too many of a single basic land', [
                                'format' => $format, 'land' => $basicName, 'quantity' => $qty, 'max' => $maxBasicSingle,
                            ]);
                            $requiredTotal = $req['exact'] ?? $totalCards;
                            $error = "Deck rejected: too many copies of \"{$basicName}\" ({$qty}). Max allowed is {$maxBasicSingle} of any single basic land type. Remove {$excess} cop" . ($excess === 1 ? 'y' : 'ies') . " and replace them with {$excess} spell(s) or other land type(s) — do NOT just delete them, the total must still reach {$requiredTotal}. Resolved card list: [{$resolvedSummary}]. Then call propose_deck again with the corrected list.";
                            $draftCandidate['validation_message'] = $error;
                            return ['error' => $error, 'draft_candidate' => $draftCandidate];
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
            ->mapWithKeys(fn ($c) => [
                $c['card_id'] => [
                    'quantity'     => $c['quantity'],
                    'is_sideboard' => false,
                    'is_commander' => strtolower((string) ($c['role'] ?? '')) === 'commander',
                ],
            ])
            ->all();
        $draft->cards()->sync($syncData);

        Log::debug('AiChatService: draft deck created', [
            'deck_id'  => $draft->id,
            'user_id'  => $user->id,
            'cards'    => count($cards),
        ]);

        $changes = $this->buildProposalChanges($conversation, $cards, $user);
        $buildBuckets = $this->buildDeckProposalBuckets($args, $cards, $user);

        return [
            'proposal' => [
                'proposal_type'     => 'deck',
                'deck_name'        => $args['deck_name'] ?? 'New Deck',
                'format'           => $args['format'] ?? null,
                'strategy_summary' => $args['strategy_summary'] ?? null,
                'cards'            => $cards,
                'added_cards'      => $changes['added_cards'],
                'removed_cards'    => $changes['removed_cards'],
                'baseline_kept_cards' => $buildBuckets['baseline_kept_cards'],
                'owned_replacement_cards' => $buildBuckets['owned_replacement_cards'],
                'missing_cards_to_buy' => $buildBuckets['missing_cards_to_buy'],
                'draft_deck_id'    => $draft->id,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, string>  $commanderColors
     * @return array<int, array<string, mixed>>|null
     */
    private function autoCompleteCommanderSingleCardShortage(
        array $cards,
        array $commanderColors,
        int $maxLands,
        int $maxBasicSingle,
        ScryfallService $scryfall
    ): ?array {
        $landCount = 0;
        $basicCounts = [];
        $basicOptions = $this->basicLandCandidatesForColors($commanderColors);

        foreach ($cards as $card) {
            if (str_contains((string) ($card['type_line'] ?? ''), 'Land')) {
                $landCount += (int) ($card['quantity'] ?? 0);
            }

            if (in_array($card['name'], $basicOptions, true)) {
                $basicCounts[$card['name']] = (int) ($card['quantity'] ?? 0);
            }
        }

        if ($landCount >= $maxLands || $basicOptions === []) {
            return null;
        }

        usort($basicOptions, function (string $left, string $right) use ($basicCounts): int {
            return ($basicCounts[$left] ?? 0) <=> ($basicCounts[$right] ?? 0);
        });

        $chosenBasic = null;
        foreach ($basicOptions as $candidate) {
            if (($basicCounts[$candidate] ?? 0) < $maxBasicSingle) {
                $chosenBasic = $candidate;
                break;
            }
        }

        if ($chosenBasic === null) {
            return null;
        }

        foreach ($cards as &$card) {
            if (($card['name'] ?? null) === $chosenBasic) {
                $card['quantity'] = ((int) ($card['quantity'] ?? 0)) + 1;
                $card['reason'] = $card['reason'] ?? 'Auto-completed to reach 100 cards.';
                return $cards;
            }
        }
        unset($card);

        $basicCard = Card::whereRaw('LOWER(name) = ?', [strtolower($chosenBasic)])->first();

        if (! $basicCard) {
            try {
                $data = $scryfall->findCard($chosenBasic);
                $basicCard = Card::updateOrCreate(
                    ['scryfall_id' => $data['scryfall_id']],
                    $data
                );
            } catch (\Throwable $e) {
                Log::warning('AiChatService: could not auto-complete 99-card commander deck with basic land', [
                    'basic_land' => $chosenBasic,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        $cards[] = [
            'card_id' => $basicCard->id,
            'name' => $basicCard->name,
            'type_line' => $basicCard->type_line,
            'mana_cost' => $basicCard->mana_cost,
            'color_identity' => $basicCard->color_identity,
            'image_uri' => $basicCard->image_uri,
            'quantity' => 1,
            'owned_quantity' => 0,
            'role' => 'land',
            'reason' => 'Auto-completed to reach 100 cards.',
        ];

        Log::info('AiChatService: auto-completed 99-card commander deck', [
            'basic_land' => $basicCard->name,
        ]);

        return $cards;
    }

    /**
     * @param  array<int, string>  $colors
     * @return array<int, string>
     */
    private function basicLandCandidatesForColors(array $colors): array
    {
        $map = [
            'W' => 'Plains',
            'U' => 'Island',
            'B' => 'Swamp',
            'R' => 'Mountain',
            'G' => 'Forest',
        ];

        $candidates = [];
        foreach ($colors as $color) {
            $land = $map[strtoupper($color)] ?? null;
            if ($land !== null) {
                $candidates[] = $land;
            }
        }

        if ($candidates === []) {
            return ['Wastes'];
        }

        return array_values(array_unique($candidates));
    }

    private function toolProposeChanges(array $args, User $user, Conversation $conversation): array
    {
        if (! $conversation->deck_id) {
            return ['error' => 'propose_changes requires an active deck attached to the conversation.'];
        }

        $added = $this->resolveChangeEntries($args['added_cards'] ?? [], $user);
        $removed = $this->resolveChangeEntries($args['removed_cards'] ?? [], $user);
        $buy = $this->resolveChangeEntries($args['buy_cards'] ?? [], $user);

        $unresolvedNames = array_values(array_unique(array_merge(
            $added['unresolved_names'],
            $removed['unresolved_names'],
            $buy['unresolved_names'],
        )));

        if ($unresolvedNames !== []) {
            $list = implode(', ', array_map(fn (string $name) => "\"{$name}\"", $unresolvedNames));

            return ['error' => "Change proposal rejected: every added, removed, or buy recommendation must use a real, exact card name. Unresolved entries: {$list}. Replace them with exact printed card names and call propose_changes again."];
        }

        if ($added['cards'] === [] && $removed['cards'] === [] && $buy['cards'] === []) {
            return ['error' => 'Change proposal rejected: no valid added_cards, removed_cards, or buy_cards were provided. Include at least one concrete card change and call propose_changes again.'];
        }

        $budget = isset($args['budget']) && is_numeric($args['budget'])
            ? round((float) $args['budget'], 2)
            : null;
        $buyList = $this->buyListFormatter->build($buy['cards'], $budget);

        return [
            'proposal' => [
                'proposal_type'     => 'changes',
                'deck_name'         => $args['deck_name'] ?? 'Deck Improvements',
                'format'            => $args['format'] ?? null,
                'strategy_summary'  => $args['strategy_summary'] ?? null,
                'cards'             => [],
                'added_cards'       => $added['cards'],
                'removed_cards'     => $removed['cards'],
                'buy_cards'         => $buy['cards'],
                'buy_list'          => $buyList,
            ],
        ];
    }

    private function resolveChangeEntries(array $entries, User $user): array
    {
        $resolution = $this->resolveEntriesWithCards($entries);
        $resolved = $resolution['resolved'];
        $resolvedIds = $resolved->pluck('card.id')->all();

        $ownedMap = $user->collection()
            ->withPivot('quantity')
            ->whereIn('cards.id', $resolvedIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($c) => $c->pivot->quantity);

        return [
            'cards' => $resolved->map(function ($item) use ($ownedMap) {
            $card = $item['card'];
            $entry = $item['entry'];
            $quantity = max(1, (int) ($entry['quantity'] ?? 1));
            $priceUsd = $card->price_usd !== null ? (float) $card->price_usd : null;

            return [
                'card_id'        => $card->id,
                'name'           => $card->name,
                'type_line'      => $card->type_line,
                'mana_cost'      => $card->mana_cost,
                'image_uri'      => $card->image_uri,
                'quantity'       => $quantity,
                'owned_quantity' => (int) ($ownedMap[$card->id] ?? 0),
                'price_usd'      => $priceUsd,
                'line_total'     => $priceUsd !== null ? round($priceUsd * $quantity, 2) : null,
                'priority'       => in_array(($entry['priority'] ?? ($entry['role'] ?? '')), ['optional', 'upgrade'], true) ? 'upgrade' : 'must-buy',
                'category'       => $entry['category'] ?? null,
                'role'           => $entry['role'] ?? null,
                'reason'         => $entry['reason'] ?? null,
                'reason_type'    => $entry['reason_type'] ?? null,
            ];
            })->values()->all(),
            'unresolved_names' => $resolution['unresolved_names'],
        ];
    }

    private function resolveEntriesWithCards(array $entries): array
    {
        $scryfall = app(ScryfallService::class);
        $unresolvedNames = [];

        $resolved = collect($entries)->map(function ($entry) use ($scryfall, &$unresolvedNames) {
            $name = trim($entry['card_name'] ?? '');
            if ($name === '') {
                return null;
            }

            $lowerName = strtolower($name);
            $isCategory = str_contains($name, '(e.g.')
                || str_contains($name, '(e.g.,')
                || preg_match('/\be\.g\b/i', $name)
                || preg_match('/^(discard|removal|counter|ramp|draw|fetch|shock|basic|dual|utility|other)\s+(spell|land|card|creature|artifact|enchantment)/i', $lowerName);

            if ($isCategory) {
                Log::warning('AiChatService: change proposal received category name instead of real card name — skipping', ['name' => $name]);
                $unresolvedNames[] = $name;
                return null;
            }

            $card = Card::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

            if (! $card) {
                try {
                    $data = $scryfall->findCard($name);
                    $card = Card::updateOrCreate(
                        ['scryfall_id' => $data['scryfall_id']],
                        $data
                    );
                } catch (\Throwable $e) {
                    Log::warning('AiChatService: could not resolve change card from Scryfall', ['name' => $name, 'error' => $e->getMessage()]);
                    $unresolvedNames[] = $name;
                    return null;
                }
            }

            return ['card' => $card, 'entry' => $entry];
        })->filter()->values();

        return [
            'resolved' => $resolved,
            'unresolved_names' => array_values(array_unique($unresolvedNames)),
        ];
    }

    private function buildProposalChanges(Conversation $conversation, array $proposedCards, User $user): array
    {
        if (! $conversation->deck_id) {
            return ['added_cards' => [], 'removed_cards' => []];
        }

        $currentDeck = Deck::where('user_id', $user->id)
            ->with(['cards' => function ($query) {
                $query->select('cards.id', 'cards.name', 'cards.type_line', 'cards.mana_cost', 'cards.image_uri');
            }])
            ->find($conversation->deck_id);

        if (! $currentDeck) {
            return ['added_cards' => [], 'removed_cards' => []];
        }

        $ownedMap = $user->collection()
            ->withPivot('quantity')
            ->get()
            ->keyBy('id')
            ->map(fn ($c) => $c->pivot->quantity);

        $currentCards = $currentDeck->cards->mapWithKeys(function ($card) use ($ownedMap) {
            return [$card->id => [
                'card_id'        => $card->id,
                'name'           => $card->name,
                'type_line'      => $card->type_line,
                'mana_cost'      => $card->mana_cost,
                'image_uri'      => $card->image_uri,
                'quantity'       => (int) $card->pivot->quantity,
                'owned_quantity' => (int) ($ownedMap[$card->id] ?? 0),
                'role'           => null,
                'reason'         => null,
            ]];
        })->all();

        $proposedMap = collect($proposedCards)->keyBy('card_id')->all();
        $allCardIds = array_unique(array_merge(array_keys($currentCards), array_keys($proposedMap)));

        $addedCards = [];
        $removedCards = [];

        foreach ($allCardIds as $cardId) {
            $before = $currentCards[$cardId] ?? null;
            $after = $proposedMap[$cardId] ?? null;

            $beforeQty = (int) ($before['quantity'] ?? 0);
            $afterQty = (int) ($after['quantity'] ?? 0);

            if ($afterQty > $beforeQty) {
                $change = $after;
                $change['quantity'] = $afterQty - $beforeQty;
                $addedCards[] = $change;
            }

            if ($beforeQty > $afterQty) {
                $change = $before;
                $change['quantity'] = $beforeQty - $afterQty;
                $removedCards[] = $change;
            }
        }

        usort($addedCards, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        usort($removedCards, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return [
            'added_cards' => array_values($addedCards),
            'removed_cards' => array_values($removedCards),
        ];
    }

    private function buildDeckProposalBuckets(array $args, array $finalCards, User $user): array
    {
        $baselineKept = $this->resolveFinalDeckSubset($args['baseline_kept_cards'] ?? [], $finalCards, $user);
        $ownedReplacements = $this->resolveFinalDeckSubset($args['owned_replacement_cards'] ?? [], $finalCards, $user, true);

        $missingCardsToBuy = collect($finalCards)
            ->filter(fn (array $card) => (int) ($card['owned_quantity'] ?? 0) <= 0)
            ->map(function (array $card) {
                return [
                    ...$card,
                    'price_usd' => $this->normalizePrice($card['card_id'] ?? null),
                    'line_total' => $this->normalizeLineTotal($card['card_id'] ?? null, (int) ($card['quantity'] ?? 1)),
                ];
            })
            ->values()
            ->all();

        return [
            'baseline_kept_cards' => $baselineKept,
            'owned_replacement_cards' => $ownedReplacements,
            'missing_cards_to_buy' => $missingCardsToBuy,
        ];
    }

    private function resolveFinalDeckSubset(array $entries, array $finalCards, User $user, bool $requireOwned = false): array
    {
        if ($entries === []) {
            return [];
        }

        $resolved = $this->resolveChangeEntries($entries, $user);
        $finalCardMap = collect($finalCards)->keyBy('card_id');

        return collect($resolved['cards'])
            ->filter(function (array $card) use ($finalCardMap, $requireOwned) {
                $final = $finalCardMap->get($card['card_id']);
                if (! $final) {
                    return false;
                }

                if ($requireOwned && (int) ($final['owned_quantity'] ?? 0) <= 0) {
                    return false;
                }

                return true;
            })
            ->map(function (array $card) use ($finalCardMap) {
                $final = $finalCardMap->get($card['card_id']);
                return [
                    ...$final,
                    'reason' => $card['reason'] ?? ($final['reason'] ?? null),
                    'price_usd' => $card['price_usd'] ?? $this->normalizePrice($card['card_id'] ?? null),
                    'line_total' => $card['line_total'] ?? $this->normalizeLineTotal($card['card_id'] ?? null, (int) ($final['quantity'] ?? 1)),
                ];
            })
            ->values()
            ->all();
    }

    private function normalizePrice(?int $cardId): ?float
    {
        if ($cardId === null) {
            return null;
        }

        $card = Card::find($cardId);
        return $card?->price_usd !== null ? (float) $card->price_usd : null;
    }

    private function normalizeLineTotal(?int $cardId, int $quantity): ?float
    {
        $price = $this->normalizePrice($cardId);
        return $price !== null ? round($price * max(1, $quantity), 2) : null;
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
                    'name'        => 'get_active_deck',
                    'description' => 'Get the full card list for the deck attached to this conversation, including owned quantities, missing quantities, and card prices. Use this when the user asks to improve, tune, upgrade, finish, or cheapest-complete an existing deck.',
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
                    'description' => 'Look up an EDHREC deck for a specific Commander. Returns the card list that human players most commonly include. Pass budget_tier to get a price-appropriate baseline: "budget" (<$50), "average" ($50–$300), "expensive" (>$300 / no limit). Pass archetype when the user specifies a theme such as aristocrats, aggro, tokens, spellslinger, or blink so the returned baseline can be interpreted through that lens even if the cached source is generic.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'commander_name' => [
                                'type'        => 'string',
                                'description' => 'Full name of the commander card, exactly as printed (e.g. "Atraxa, Praetors\' Voice").',
                            ],
                            'budget_tier' => [
                                'type'        => 'string',
                                'enum'        => ['budget', 'average', 'expensive'],
                                'description' => 'Price tier for the EDHREC deck. "budget" for decks under ~$50, "expensive" for decks over ~$300 or no budget limit, "average" otherwise.',
                            ],
                            'archetype' => [
                                'type'        => 'string',
                                'description' => 'Optional strategy tag for the build, such as aristocrats, aggro, tokens, spellslinger, sacrifice, reanimator, or blink.',
                            ],
                        ],
                        'required' => ['commander_name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'propose_changes',
                    'description' => 'Present upgrade recommendations for an existing attached deck. Use this for deck tuning, swap suggestions, buy recommendations, and cheapest-completion plans instead of rebuilding the full deck. It is valid to submit only buy_cards when the user wants to finish the current deck as cheaply as possible.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'deck_name' => [
                                'type'        => 'string',
                                'description' => 'Name of the deck being improved.',
                            ],
                            'format' => [
                                'type'        => 'string',
                                'description' => 'MTG format (e.g. commander, modern, standard).',
                            ],
                            'strategy_summary' => [
                                'type'        => 'string',
                                'description' => 'Short summary of what the upgrade is trying to improve.',
                            ],
                            'budget' => [
                                'type' => 'number',
                                'description' => 'Optional buy budget cap in USD for the recommended cards.',
                            ],
                            'added_cards' => [
                                'type' => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'card_name' => ['type' => 'string'],
                                        'quantity'  => ['type' => 'integer', 'minimum' => 1],
                                        'role'      => ['type' => 'string'],
                                        'reason'    => ['type' => 'string'],
                                    ],
                                    'required' => ['card_name'],
                                ],
                                'description' => 'Cards to add to the current deck.',
                            ],
                            'removed_cards' => [
                                'type' => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'card_name' => ['type' => 'string'],
                                        'quantity'  => ['type' => 'integer', 'minimum' => 1],
                                        'priority'  => ['type' => 'string', 'enum' => ['must-buy', 'optional']],
                                        'category'  => ['type' => 'string', 'enum' => ['commander', 'mainboard', 'sideboard', 'upgrade']],
                                        'role'      => ['type' => 'string'],
                                        'reason'    => ['type' => 'string'],
                                    ],
                                    'required' => ['card_name'],
                                ],
                                'description' => 'Cards to remove from the current deck.',
                            ],
                            'buy_cards' => [
                                'type' => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'card_name' => ['type' => 'string'],
                                        'quantity'  => ['type' => 'integer', 'minimum' => 1],
                                        'role'      => ['type' => 'string'],
                                        'reason'    => ['type' => 'string'],
                                    ],
                                    'required' => ['card_name'],
                                ],
                                'description' => 'Cards worth buying that are not currently in the deck.',
                            ],
                        ],
                        'required' => ['deck_name'],
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
                            'color_identity' => [
                                'type'        => 'array',
                                'items'       => ['type' => 'string', 'enum' => ['W', 'U', 'B', 'R', 'G']],
                                'description' => 'The intended color identity of the deck (e.g. ["R"] for mono-red, ["U","B"] for Dimir). Every non-colorless card submitted must have a color identity that is a subset of this list. Required for all formats.',
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
                            'baseline_kept_cards' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'card_name' => ['type' => 'string'],
                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        'role' => ['type' => 'string'],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['card_name'],
                                ],
                                'description' => 'Optional subset of final deck cards that stayed in the list from the commander baseline without being replaced.',
                            ],
                            'owned_replacement_cards' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'card_name' => ['type' => 'string'],
                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        'role' => ['type' => 'string'],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['card_name'],
                                ],
                                'description' => 'Optional subset of final deck cards that were chosen as owned replacements for baseline cards.',
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

    private function systemPrompt(Conversation $conversation): string
    {
        $deckContext = $this->activeDeckPromptContext($conversation);

        return <<<PROMPT
You are VaultMage, an expert Magic: The Gathering deck building assistant.

Your job is to build Commander decks and improve existing decks. When a user describes what they want, start working immediately.

═══════════════════════════════════════
NEW DECK BUILDS — COMMANDER ONLY
═══════════════════════════════════════

New deck builds are Commander / EDH only. If the user asks for another format, politely explain that new builds are Commander-only for now and ask for a commander name.

To start a new Commander build you need:
1. Commander name
2. Budget (e.g. "under $50", "no limit", "only cards I own")
3. Archetype / playstyle when the user has one (e.g. aristocrats, aggro, tokens, spellslinger)
If commander or budget is missing, ask for both in one message. If the user has not given an archetype, ask for one unless they clearly want a generic best-version build.

BUILD WORKFLOW — follow these steps in order, every time:

Step 1 — call get_commander_guide with the commander name, the correct budget_tier, and the user's archetype if they gave one:
  - budget  → user budget is under ~$50
  - average → user budget is roughly $50–$300 (default if no budget stated)
  - expensive → user budget is over ~$300 or "no limit"
  This returns an EDHREC deck tuned for that price range: a human-curated 100-card baseline that is already color-legal and properly structured.
  If the archetype is not explicitly encoded in the source, still use the requested archetype as a hard deck-building constraint for substitutions and final card choices.
  If EDHREC data is not found for that tier, the tool falls back to the average tier automatically.

Step 2 — call get_collection (filtered to the commander's colors and "commander" format).
  - Do this once. Do not repeat it.

Step 3 — build the final 100-card list:

  ── PATH A: EDHREC baseline available ──
  a. START with every card in the EDHREC list (it is already 100 cards including the commander). Treat it as the target shell, not as untouchable truth.
  b. For each EDHREC card the user OWNS: keep it as-is.
  c. For each EDHREC card the user does NOT own:
       - First try to substitute with an owned card that serves the same slot.
       - A valid slot match means all of these are close: deck role, mana value / curve position, and synergy with the requested archetype.
       - Prefer archetype-consistent substitutions over generic "goodstuff" cards.
       - Example: in aristocrats, replace Blood Artist with Zulaport Cutthroat or Bastion of Remembrance before using a random black staple.
       - If no suitable owned substitute: keep the original card (the user will buy it).
       - Only search for cheaper alternatives if the user gave an explicit budget AND the card exceeds it.
  d. After substitutions the deck is still 100 cards. Do not pad with lands.
  e. The final deck should preserve the requested archetype. If too many substitutions would dilute the theme, keep more missing baseline cards and surface them as buy recommendations instead of weakening the deck.
  f. Track three explicit buckets while building:
       - baseline_kept_cards: cards still in the final deck from the original baseline
       - owned_replacement_cards: owned cards used instead of baseline cards
       - missing_cards_to_buy: final-deck cards with owned_quantity = 0
     The first two buckets should be submitted in propose_deck. The buy bucket is derived server-side from the final resolved deck.

  ── PATH B: No EDHREC data ──
  Build a full 100-card list from your own knowledge using this exact structure, biased heavily toward the requested archetype:
    1  commander (with role "commander")
   10  ramp cards (Sol Ring, Arcane Signet, Mind Stone, Fellwar Stone, Thought Vessel, Worn Powerstone, Hedron Archive, Gilded Lotus, Thran Dynamo, Commander's Sphere, …)
   10  card draw spells (Rhystic Study, Mystic Remora, Phyrexian Arena, Necropotence, Read the Bones, Sign in Blood, Night's Whisper, Painful Truths, Thorough Investigation, Wheels, …)
    6  targeted removal
    3  board wipes
    4  counterspells (for blue commanders)
   28  other on-theme spells, creatures, and artifacts that fit the strategy
   38  lands (basic lands + Command Tower + a handful of utility lands)
  ─────
  100  TOTAL

  Mentally count your list to 100 before calling propose_deck. You know hundreds of real card names — write them all out. Do NOT call propose_deck until you have exactly 100 named.

Step 4 — call propose_deck with the complete 100-card list.
  - Do not call propose_deck until your count is exactly 100.
  - Set color_identity to the commander's color identity.
  - Set format to "commander".
  - Include the commander card with role "commander".
  - In strategy_summary, explicitly mention the archetype and whether the list is generic baseline, collection-adapted, or collection-first.
  - When you used a commander baseline, also pass:
      - baseline_kept_cards
      - owned_replacement_cards
    These should be subsets of the final 100-card list and should use exact printed card names.

IMPORTANT — tool economy:
- get_collection once, get_commander_guide once. Then go straight to Step 3.
- Only use search_scryfall when you are genuinely unsure of an exact card name — not to discover new cards to fill slots.
- After a propose_deck rejection, make surgical fixes only. Do not restart.

═══════════════════════════════════════
DECK RULES (enforced server-side)
═══════════════════════════════════════

Card count: exactly 100 cards. Commander counts as 1 of the 100.
Singleton: max 1 copy of every non-basic-land card.
Basic lands (Plains, Island, Swamp, Mountain, Forest, Wastes, Snow-Covered basics) may appear in any quantity.
Land count: target 36–38 total (hard max 40). NEVER more than 25 of any single basic land type.
Non-land cards: you need at least 62 spells/creatures/artifacts. A 100-card deck with fewer than 62 non-lands is unplayable — do not pad missing slots with basic lands.

Color identity (STRICTLY enforced):
- Always pass `color_identity` in propose_deck — use the commander's color identity.
- Every non-colorless card MUST have a color identity that is a subset of the commander's colors. No exceptions.
- Colorless cards and artifacts with no color identity are always allowed.

CRITICAL — card_name rules for propose_deck:
- Every card_name MUST be an exact printed Magic card name: "Sol Ring", "Lightning Bolt", "Island".
- NEVER use category names: "Ramp spell", "Fetch land (e.g. Scalding Tarn)", "Removal", etc.
- If unsure of an exact name, use search_scryfall to confirm it first.

Repair (when propose_deck returns a count error):
- The error message tells you the FILL BREAKDOWN: exactly how many spells and how many lands to add.
- Follow that breakdown precisely. Do NOT substitute all-lands for all-spells.
- To add spells: name real cards from your own knowledge (e.g. Opt, Consider, Preordain, Serum Visions, Arcane Denial, Negate, Swan Song, Into the Roil, Capsize, Ponder, Brainstorm, Impulse, Frantic Search, Mana Leak, etc.). You do NOT need to call search_scryfall for well-known cards.
- Only call search_scryfall if you genuinely cannot recall an exact card name — not to discover filler cards.
- Only add basic lands if the FILL BREAKDOWN says to. Never add more than the allowed number of lands.

Budget:
- price_usd is included on every card. Track the running buy total as you select unowned cards.
- Cards the user owns cost $0. Cards with null price_usd are treated as $0.
- If the user says "only cards I own", treat that as a hard cap of $0 and maximize coherence within the archetype using collection cards only.
- Treat the user's budget as a target, not a hard cap.
- A small overshoot is acceptable if it keeps the deck coherent or avoids a major downgrade.
- As a rule, keep bought cards within the smaller of $15 or 10% over the stated budget unless the user asked for a stricter cap.

═══════════════════════════════════════
IMPROVING AN EXISTING DECK
═══════════════════════════════════════

- Call get_active_deck early if a deck is attached to the conversation.
- If the user refers to "this deck" but no deck is attached, ask them to open the deck first.
- Prefer propose_changes for tuning, swaps, and buy recommendations. Only use propose_deck if the user explicitly asks for a full rebuild.
- When improving, clearly separate: (1) cuts, (2) owned cards to swap in, (3) cards worth buying.
- When the user asks what to buy, prioritize what they don't own and offer cheaper alternatives.
- When the user asks to "finish this deck", "complete this deck", or "finish this deck as cheaply as possible":
  - Keep the current deck list fixed unless the user explicitly asks for upgrades or swaps.
  - Call get_active_deck and use the missing quantities already returned there.
  - Call propose_changes with buy_cards for the exact missing cards needed to complete the deck.
  - Do not add optional upgrades in that flow unless the user asks for them.
  - Optimize for the lowest completion cost first; if some cards are unpriced, say that the total is only a partial estimate.

═══════════════════════════════════════
GENERAL
═══════════════════════════════════════

- Keep conversational text short and direct.
- When you have a complete card list ready, call propose_deck immediately — do not describe what you are about to do.

{$deckContext}
PROMPT;
    }

    private function activeDeckPromptContext(Conversation $conversation): string
    {
        if (! $conversation->deck_id) {
            return 'Active deck context: none attached to this conversation.';
        }

        $deck = Deck::withSum('cards as cards_sum_quantity', 'deck_cards.quantity')->find($conversation->deck_id);

        if (! $deck) {
            return 'Active deck context: attached deck not found.';
        }

        $colors = empty($deck->color_identity) ? 'colorless or unspecified' : implode('/', $deck->color_identity);
        $format = $deck->format ?? 'unknown';
        $count = (int) ($deck->cards_sum_quantity ?? 0);
        $description = $deck->description ? ' Description: ' . $deck->description : '';

        return "Active deck context: id {$deck->id}, name \"{$deck->name}\", format {$format}, colors {$colors}, total cards {$count}.{$description}";
    }
}
