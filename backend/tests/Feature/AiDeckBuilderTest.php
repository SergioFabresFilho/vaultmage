<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Conversation;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDeckBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user         = User::factory()->create();
        $this->conversation = Conversation::create(['user_id' => $this->user->id, 'title' => null]);
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_send_messages(): void
    {
        $response = $this->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
            'message' => 'Build me a deck',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_cannot_send_messages_to_another_users_conversation(): void
    {
        $other        = User::factory()->create();
        $otherConv    = Conversation::create(['user_id' => $other->id, 'title' => null]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$otherConv->id}/messages", [
                'message' => 'Build me a deck',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_create_conversation_attached_to_their_deck(): void
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/chat/conversations', ['deck_id' => $deck->id]);

        $response->assertCreated();
        $response->assertJsonPath('deck.id', $deck->id);
        $this->assertDatabaseHas('conversations', [
            'id'      => $response->json('id'),
            'user_id' => $this->user->id,
            'deck_id' => $deck->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing info
    // -------------------------------------------------------------------------

    public function test_ai_asks_for_missing_info_when_request_is_vague(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                $this->textResponse('What format, playstyle, and budget did you have in mind?'),
                200
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a deck',
            ]);

        $response->assertOk();
        $response->assertJsonPath('deck_proposal', null);
        $this->assertStringContainsString(
            'format',
            strtolower($response->json('message.content'))
        );

        // User message + assistant message stored
        $this->assertDatabaseCount('messages', 2);
    }

    // -------------------------------------------------------------------------
    // Happy path — full deck build
    // -------------------------------------------------------------------------

    public function test_full_deck_build_flow_proposes_and_saves_draft(): void
    {
        // Arrange — 20 Islands + 10 non-land cards × 4 copies = 60 cards
        $this->makeCard('Island', 'Basic Land — Island', 20);

        $nonLandNames = [
            'Opt', 'Counterspell', 'Brainstorm', 'Ponder', 'Preordain',
            'Mana Leak', 'Snapcaster Mage', 'Pteramander', 'Delver of Secrets',
            'Vapor Snag',
        ];
        foreach ($nonLandNames as $name) {
            $this->makeCard($name, 'Instant', 4);
        }

        $deckCards = [['card_name' => 'Island', 'quantity' => 20, 'role' => 'land', 'reason' => 'mana']];
        foreach ($nonLandNames as $name) {
            $deckCards[] = ['card_name' => $name, 'quantity' => 4, 'role' => 'spell', 'reason' => 'good'];
        }

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                // Round 1: AI fetches the user's collection
                ->push($this->toolCallResponse('get_collection', [], 'call_1'), 200)
                // Round 2: AI proposes the deck
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name'        => 'Blue Tempo',
                    'format'           => 'standard',
                    'strategy_summary' => 'Fast tempo with counter magic.',
                    'cards'            => $deckCards,
                ], 'call_2'), 200)
                // Round 3: final text
                ->push($this->textResponse('Here is your 60-card Blue Tempo deck!'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a standard tempo deck under $100',
            ]);

        $response->assertOk();
        $response->assertJsonPath('message.content', 'Here is your 60-card Blue Tempo deck!');

        $proposal = $response->json('deck_proposal');
        $this->assertNotNull($proposal);
        $this->assertEquals('Blue Tempo', $proposal['deck_name']);
        $this->assertEquals('standard', $proposal['format']);
        $this->assertCount(11, $proposal['cards']); // Island + 10 non-land types

        // Draft deck persisted to DB
        $this->assertDatabaseHas('decks', [
            'user_id'  => $this->user->id,
            'name'     => 'Blue Tempo',
            'format'   => 'standard',
            'is_draft' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // price_usd is forwarded to the AI
    // -------------------------------------------------------------------------

    public function test_collection_tool_result_includes_price_usd(): void
    {
        $this->makeCard('Sol Ring', 'Artifact', 1, priceUsd: 1.50);

        // First call: AI requests collection; second call: AI gives a text reply
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_collection', [], 'call_1'), 200)
                ->push($this->textResponse('I see your collection.'), 200),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'What cards do I own?',
            ]);

        // The tool result is stored as a 'tool' message in the DB — verify it has price_usd
        $toolMessage = $this->conversation->messages()->where('role', 'tool')->first();
        $this->assertNotNull($toolMessage);

        $content = json_decode($toolMessage->content, true);
        $card    = collect($content['cards'])->firstWhere('name', 'Sol Ring');
        $this->assertNotNull($card);
        $this->assertEquals(1.50, $card['price_usd']);
    }

    // -------------------------------------------------------------------------
    // Card-count validation + retry
    // -------------------------------------------------------------------------

    public function test_propose_deck_is_retried_when_card_count_is_wrong(): void
    {
        // Create the cards that will be in the valid second proposal
        $this->makeCard('Island', 'Basic Land — Island', 20);

        $nonLandNames = [
            'Opt', 'Counterspell', 'Brainstorm', 'Ponder', 'Preordain',
            'Mana Leak', 'Snapcaster Mage', 'Pteramander', 'Delver of Secrets',
            'Vapor Snag',
        ];
        foreach ($nonLandNames as $name) {
            $this->makeCard($name, 'Instant', 4);
        }

        $validCards = [['card_name' => 'Island', 'quantity' => 20, 'role' => 'land', 'reason' => 'mana']];
        foreach ($nonLandNames as $name) {
            $validCards[] = ['card_name' => $name, 'quantity' => 4, 'role' => 'spell', 'reason' => 'good'];
        }

        // Only 59 cards — missing one
        $shortCards   = $validCards;
        $shortCards[0]['quantity'] = 19; // 19 Islands instead of 20

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                // Round 1: AI proposes 59 cards (should be rejected)
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Blue Tempo',
                    'format'    => 'standard',
                    'cards'     => $shortCards,
                ], 'call_1'), 200)
                // Round 2: AI corrects to 60 cards
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Blue Tempo',
                    'format'    => 'standard',
                    'cards'     => $validCards,
                ], 'call_2'), 200)
                ->push($this->textResponse('Fixed! Here is your corrected deck.'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a standard deck',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('deck_proposal'));

        // Only one draft saved (the valid one from call_2)
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseHas('decks', ['is_draft' => true]);
    }

    public function test_existing_deck_context_can_be_loaded_for_upgrade_advice(): void
    {
        $ownedUpgrade = $this->makeCard('Arcane Signet', 'Artifact', 1, priceUsd: 1.25);
        $deckCard = $this->makeCard('Command Tower', 'Land', 0, priceUsd: 0.99);

        $deck = Deck::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Dimir Control',
            'format' => 'commander',
            'color_identity' => ['U', 'B'],
        ]);
        $deck->cards()->attach($deckCard->id, ['quantity' => 1, 'is_sideboard' => false]);

        $this->conversation->update(['deck_id' => $deck->id]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_active_deck', [], 'call_1'), 200)
                ->push($this->toolCallResponse('get_collection', [], 'call_2'), 200)
                ->push($this->textResponse('Cut weaker ramp and add Arcane Signet from your collection.'), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Look at this deck and my collection. What can I change?',
            ]);

        $response->assertOk();
        $response->assertJsonPath('message.content', 'Cut weaker ramp and add Arcane Signet from your collection.');

        $toolMessages = $this->conversation->fresh()->messages()->where('role', 'tool')->get();
        $this->assertCount(2, $toolMessages);

        $activeDeckPayload = json_decode($toolMessages[0]->content, true);
        $this->assertEquals('Dimir Control', $activeDeckPayload['active_deck']['name']);
        $this->assertEquals(1, $activeDeckPayload['active_deck']['total_cards']);
        $this->assertEquals('Command Tower', $activeDeckPayload['active_deck']['cards'][0]['name']);

        $collectionPayload = json_decode($toolMessages[1]->content, true);
        $this->assertEquals($ownedUpgrade->name, $collectionPayload['cards'][0]['name']);
    }

    public function test_deck_upgrade_proposal_returns_added_and_removed_cards(): void
    {
        $cut = $this->makeCard('Cancel', 'Instant', 0, priceUsd: 0.10);
        $keep = $this->makeCard('Island', 'Basic Land — Island', 10, priceUsd: 0.02);
        $add = $this->makeCard('Counterspell', 'Instant', 2, priceUsd: 1.50);

        $deck = Deck::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Blue Control',
            'format' => 'casual',
        ]);
        $deck->cards()->attach($cut->id, ['quantity' => 2, 'is_sideboard' => false]);
        $deck->cards()->attach($keep->id, ['quantity' => 20, 'is_sideboard' => false]);

        $this->conversation->update(['deck_id' => $deck->id]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('propose_changes', [
                    'deck_name' => 'Blue Control',
                    'format' => 'casual',
                    'strategy_summary' => 'Upgrade the interaction suite.',
                    'added_cards' => [
                        ['card_name' => 'Counterspell', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                    'removed_cards' => [
                        ['card_name' => 'Cancel', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                ], 'call_1'), 200)
                ->push($this->textResponse('Swap Cancel for Counterspell.'), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Improve this deck.',
            ]);

        $response->assertOk();
        $proposal = $response->json('deck_proposal');
        $this->assertNotNull($proposal);
        $this->assertEquals('changes', $proposal['proposal_type']);
        $this->assertCount(1, $proposal['added_cards']);
        $this->assertCount(1, $proposal['removed_cards']);
        $this->assertNull($proposal['draft_deck_id'] ?? null);
        $this->assertEquals('Counterspell', $proposal['added_cards'][0]['name']);
        $this->assertEquals(2, $proposal['added_cards'][0]['quantity']);
        $this->assertEquals('Cancel', $proposal['removed_cards'][0]['name']);
        $this->assertEquals(2, $proposal['removed_cards'][0]['quantity']);
    }

    public function test_deck_upgrade_proposal_retries_when_change_cards_are_unresolved(): void
    {
        $cut = $this->makeCard('Cancel', 'Instant', 0, priceUsd: 0.10);
        $add = $this->makeCard('Counterspell', 'Instant', 2, priceUsd: 1.50);

        $deck = Deck::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Blue Control',
            'format' => 'casual',
        ]);
        $deck->cards()->attach($cut->id, ['quantity' => 2, 'is_sideboard' => false]);

        $this->conversation->update(['deck_id' => $deck->id]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('propose_changes', [
                    'deck_name' => 'Blue Control',
                    'format' => 'casual',
                    'strategy_summary' => 'Upgrade the interaction suite.',
                    'added_cards' => [
                        ['card_name' => 'Counter magic', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                    'removed_cards' => [
                        ['card_name' => 'Cancel', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                ], 'call_1'), 200)
                ->push($this->toolCallResponse('propose_changes', [
                    'deck_name' => 'Blue Control',
                    'format' => 'casual',
                    'strategy_summary' => 'Upgrade the interaction suite.',
                    'added_cards' => [
                        ['card_name' => 'Counterspell', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                    'removed_cards' => [
                        ['card_name' => 'Cancel', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                ], 'call_2'), 200)
                ->push($this->textResponse('Swap Cancel for Counterspell.'), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Improve this deck.',
            ]);

        $response->assertOk();
        $proposal = $response->json('deck_proposal');
        $this->assertNotNull($proposal);
        $this->assertCount(1, $proposal['added_cards']);
        $this->assertEquals('Counterspell', $proposal['added_cards'][0]['name']);

        $toolMessages = $this->conversation->messages()->where('role', 'tool')->get();
        $this->assertCount(2, $toolMessages);
        $this->assertStringContainsString(
            'Change proposal rejected',
            $toolMessages->first()->content
        );
    }

    public function test_deck_improvement_conversation_hits_lower_tool_round_cap(): void
    {
        $deck = Deck::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Slow Loop Deck',
            'format' => 'commander',
        ]);
        $this->conversation->update(['deck_id' => $deck->id]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_active_deck', [], 'call_1'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_2'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_3'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_4'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_5'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_6'), 200)
                ->push($this->toolCallResponse('get_active_deck', [], 'call_7'), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Keep improving this deck forever.',
            ]);

        $response->assertStatus(502);
        $this->assertStringContainsString(
            'maximum number of tool-call rounds (6)',
            $response->json('message')
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCard(
        string $name,
        string $typeLine = 'Instant',
        int $ownedQty = 0,
        ?float $priceUsd = null,
    ): Card {
        $card = Card::factory()->create([
            'name'      => $name,
            'type_line' => $typeLine,
            'price_usd' => $priceUsd,
        ]);

        if ($ownedQty > 0) {
            $this->user->collection()->attach($card->id, ['quantity' => $ownedQty, 'foil' => false]);
        }

        return $card;
    }

    private function toolCallResponse(string $tool, array $args, string $callId = 'call_test'): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role'       => 'assistant',
                    'content'    => null,
                    'tool_calls' => [[
                        'id'       => $callId,
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tool,
                            'arguments' => json_encode($args),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function textResponse(string $text): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role'       => 'assistant',
                    'content'    => $text,
                    'tool_calls' => [],
                ],
            ]],
        ];
    }
}
