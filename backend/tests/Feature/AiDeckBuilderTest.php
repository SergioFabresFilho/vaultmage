<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Conversation;
use App\Models\Deck;
use App\Models\Message;
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

    public function test_user_can_delete_their_own_conversation(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/chat/conversations/{$this->conversation->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Conversation deleted');

        $this->assertDatabaseMissing('conversations', [
            'id' => $this->conversation->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_conversation(): void
    {
        $other = User::factory()->create();
        $otherConv = Conversation::create(['user_id' => $other->id, 'title' => null]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/chat/conversations/{$otherConv->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('conversations', [
            'id' => $otherConv->id,
        ]);
    }

    public function test_create_deck_rejects_invalid_assistant_proposal(): void
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Rejected deck.',
            'metadata' => [
                'proposal_type' => 'deck',
                'deck_name' => 'Broken Scarab Deck',
                'format' => 'commander',
                'strategy_summary' => 'Invalid list.',
                'cards' => [],
                'draft_deck_id' => null,
                'validation_message' => 'Deck rejected: commander requires exactly 100 cards.',
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/create-deck", [
                'message_id' => $message->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Deck rejected: commander requires exactly 100 cards.');
        $this->assertDatabaseCount('decks', 0);
    }

    public function test_create_deck_uses_valid_assistant_message_metadata(): void
    {
        $island = Card::factory()->create(['name' => 'Island']);
        $opt = Card::factory()->create(['name' => 'Opt']);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Valid deck.',
            'metadata' => [
                'proposal_type' => 'deck',
                'deck_name' => 'Blue Tempo',
                'format' => 'standard',
                'strategy_summary' => 'Tempo shell.',
                'cards' => [
                    ['card_id' => $island->id, 'quantity' => 20],
                    ['card_id' => $opt->id, 'quantity' => 4],
                ],
                'draft_deck_id' => null,
                'validation_message' => null,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/create-deck", [
                'message_id' => $message->id,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('decks', [
            'user_id' => $this->user->id,
            'name' => 'Blue Tempo',
            'format' => 'standard',
            'is_draft' => false,
        ]);
        $this->assertDatabaseHas('deck_cards', [
            'deck_id' => $response->json('id'),
            'card_id' => $island->id,
            'quantity' => 20,
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
        // Attach a deck so the conversation runs as an improvement session
        // (new builds are commander-only; improvement sessions allow any format).
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'format' => 'standard']);
        $this->conversation->update(['deck_id' => $deck->id]);

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
        // Attach a deck so the conversation runs as an improvement session
        // (new builds are commander-only; improvement sessions allow any format).
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'format' => 'standard']);
        $this->conversation->update(['deck_id' => $deck->id]);

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

        // Only one draft saved (the valid one from call_2); plus the attached improvement deck = 2 total
        $this->assertDatabaseCount('decks', 2);
        $this->assertDatabaseHas('decks', ['is_draft' => true]);
    }

    public function test_new_deck_build_exits_gracefully_when_search_loop_repeats(): void
    {
        $this->makeCard('Krenko, Mob Boss', 'Legendary Creature — Goblin Warrior', 0, priceUsd: 5.00);
        $this->makeCard('Goblin Chieftain', 'Creature — Goblin', 0, priceUsd: 2.00);
        $this->makeCard('Goblin Trashmaster', 'Creature — Goblin Warrior', 0, priceUsd: 1.00);
        $this->makeCard('Mountain', 'Basic Land — Mountain', 0, priceUsd: 0.05);

        $shortDeck = [
            ['card_name' => 'Krenko, Mob Boss', 'quantity' => 1, 'role' => 'commander'],
            ['card_name' => 'Goblin Chieftain', 'quantity' => 1, 'role' => 'anthem'],
            ['card_name' => 'Mountain', 'quantity' => 34, 'role' => 'land'],
        ];

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Krenko Aggro',
                    'format' => 'commander',
                    'cards' => $shortDeck,
                ], 'call_1'), 200)
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'goblin',
                    'colors' => ['R'],
                    'format' => 'commander',
                ], 'call_2'), 200)
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Krenko Aggro',
                    'format' => 'commander',
                    'cards' => array_merge($shortDeck, [
                        ['card_name' => 'Goblin Trashmaster', 'quantity' => 1, 'role' => 'payoff'],
                    ]),
                ], 'call_3'), 200)
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'goblin',
                    'colors' => ['R'],
                    'format' => 'commander',
                ], 'call_4'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a Krenko commander deck under $100',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('deck_proposal'));
        $response->assertJsonPath('deck_proposal.draft_deck_id', null);
        $this->assertNotNull($response->json('deck_proposal.validation_message'));
        $this->assertStringContainsString(
            'looping on the same search',
            $response->json('message.content')
        );
    }

    public function test_new_deck_build_exits_gracefully_when_max_rounds_are_spent_on_searches(): void
    {
        $this->makeCard('Krenko, Mob Boss', 'Legendary Creature — Goblin Warrior', 0, priceUsd: 5.00);
        $this->makeCard('Mountain', 'Basic Land — Mountain', 0, priceUsd: 0.05);
        $this->makeCard('Sol Ring', 'Artifact', 0, priceUsd: 1.50);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_collection', ['colors' => ['R'], 'format' => 'commander'], 'call_1'), 200)
                ->push($this->toolCallResponse('get_commander_guide', ['commander_name' => 'Krenko, Mob Boss'], 'call_2'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'goblin', 'colors' => ['R'], 'format' => 'commander'], 'call_3'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'artifact ramp', 'colors' => ['R'], 'format' => 'commander'], 'call_4'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'krenko goblin commander tokens'], 'call_5'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mana rock red commander'], 'call_6'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'mana rock', 'colors' => ['R'], 'format' => 'commander'], 'call_7'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'krenko mob boss commander decklist'], 'call_8'), 200)
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Krenko Aggro',
                    'format' => 'commander',
                    'cards' => [
                        ['card_name' => 'Krenko, Mob Boss', 'quantity' => 1, 'role' => 'commander'],
                        ['card_name' => 'Mountain', 'quantity' => 35, 'role' => 'land'],
                        ['card_name' => 'Sol Ring', 'quantity' => 1, 'role' => 'ramp'],
                    ],
                ], 'call_9'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono red commander draw goblin'], 'call_10'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'red goblin token payoff'], 'call_11'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'goblin token', 'colors' => ['R'], 'format' => 'commander'], 'call_12'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'commander goblin token draw red'], 'call_13'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'red draw', 'colors' => ['R'], 'format' => 'commander'], 'call_14'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono red goblin haste payoff'], 'call_15'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'goblin haste', 'colors' => ['R'], 'format' => 'commander'], 'call_16'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono red commander goblin finisher'], 'call_17'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Help me build a Krenko commander deck under $250',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('deck_proposal'));
        $this->assertNull($response->json('deck_proposal.draft_deck_id'));
        $this->assertNotNull($response->json('deck_proposal.validation_message'));
        $this->assertStringContainsString(
            'stopped before wasting more tool calls',
            strtolower($response->json('message.content'))
        );
    }

    public function test_new_deck_build_uses_higher_round_cap_before_graceful_fallback(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_collection', [], 'call_1'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'tempo', 'format' => 'standard'], 'call_2'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'draw', 'format' => 'standard'], 'call_3'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'removal', 'format' => 'standard'], 'call_4'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'counterspell', 'format' => 'standard'], 'call_5'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'cheap flyers', 'format' => 'standard'], 'call_6'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard tempo'], 'call_7'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard draw spell'], 'call_8'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard instant removal'], 'call_9'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard cheap blue creature'], 'call_10'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'bounce', 'format' => 'standard'], 'call_11'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'cantrip', 'format' => 'standard'], 'call_12'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard one mana trick'], 'call_13'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard sideboard hate'], 'call_14'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'dual land', 'format' => 'standard'], 'call_15'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'finisher', 'format' => 'standard'], 'call_16'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'f:standard tempo finisher'], 'call_17'), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a new standard tempo deck.',
            ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'stopped before wasting more tool calls',
            strtolower($response->json('message.content'))
        );
    }

    public function test_new_deck_build_gets_repair_rounds_after_invalid_proposal(): void
    {
        $this->makeCard('Syr Konrad, the Grim', 'Legendary Creature — Human Knight', 0, priceUsd: 1.50)
            ->update(['color_identity' => ['B']]);
        $this->makeCard('Swamp', 'Basic Land — Swamp', 0, priceUsd: 0.05)
            ->update(['color_identity' => ['B']]);
        $this->makeCard('Hapatra, Vizier of Poisons', 'Legendary Creature — Human Cleric', 0, priceUsd: 0.50)
            ->update(['color_identity' => ['B', 'G']]);
        $this->makeCard('Murder', 'Instant', 0, priceUsd: 0.10)
            ->update(['color_identity' => ['B']]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_collection', ['colors' => ['B'], 'format' => 'commander'], 'call_1'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'draw', 'colors' => ['B'], 'format' => 'commander'], 'call_2'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'ramp', 'colors' => ['B'], 'format' => 'commander'], 'call_3'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'tokens', 'colors' => ['B'], 'format' => 'commander'], 'call_4'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'sacrifice', 'colors' => ['B'], 'format' => 'commander'], 'call_5'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono black commander draw'], 'call_6'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono black sacrifice outlet'], 'call_7'), 200)
                ->push($this->toolCallResponse('search_scryfall', ['query' => 'mono black commander payoff'], 'call_8'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'graveyard', 'colors' => ['B'], 'format' => 'commander'], 'call_9'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'zombie', 'colors' => ['B'], 'format' => 'commander'], 'call_10'), 200)
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Syr Konrad Combo',
                    'format' => 'commander',
                    'cards' => [
                        ['card_name' => 'Syr Konrad, the Grim', 'quantity' => 1, 'role' => 'commander'],
                        ['card_name' => 'Swamp', 'quantity' => 36, 'role' => 'land'],
                        ['card_name' => 'Hapatra, Vizier of Poisons', 'quantity' => 1, 'role' => 'combo_piece'],
                    ],
                ], 'call_11'), 200)
                ->push($this->toolCallResponse('propose_changes', [
                    'deck_name' => 'Syr Konrad Combo',
                    'format' => 'commander',
                    'removed_cards' => [
                        ['card_name' => 'Hapatra, Vizier of Poisons', 'quantity' => 1, 'role' => 'combo_piece'],
                    ],
                    'added_cards' => [
                        ['card_name' => 'Murder', 'quantity' => 1, 'role' => 'interaction'],
                    ],
                ], 'call_12'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'creature', 'colors' => ['B'], 'format' => 'commander'], 'call_13'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'removal', 'colors' => ['B'], 'format' => 'commander'], 'call_14'), 200)
                // Two extra repair rounds to exhaust MAX_REPAIR_ROUNDS_PER_PROPOSAL (now 5)
                ->push($this->toolCallResponse('search_cards', ['query' => 'discard', 'colors' => ['B'], 'format' => 'commander'], 'call_15'), 200)
                ->push($this->toolCallResponse('search_cards', ['query' => 'tutor', 'colors' => ['B'], 'format' => 'commander'], 'call_16'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a mono-black Syr Konrad commander deck.',
            ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'repair budget',
            strtolower($response->json('message.content'))
        );
        $this->assertNull($response->json('deck_proposal.draft_deck_id'));
        $this->assertStringContainsString(
            'color identity violation',
            strtolower($response->json('deck_proposal.validation_message'))
        );
    }

    public function test_short_count_repair_mode_blocks_exploratory_searches(): void
    {
        $this->makeCard('Syr Konrad, the Grim', 'Legendary Creature — Human Knight', 0, priceUsd: 1.50)
            ->update(['color_identity' => ['B']]);
        $this->makeCard('Swamp', 'Basic Land — Swamp', 0, priceUsd: 0.05)
            ->update(['color_identity' => ['B']]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Syr Konrad Combo',
                    'format' => 'commander',
                    'cards' => [
                        ['card_name' => 'Syr Konrad, the Grim', 'quantity' => 1, 'role' => 'commander'],
                        ['card_name' => 'Swamp', 'quantity' => 63, 'role' => 'land'],
                    ],
                ], 'call_1'), 200)
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'zombie',
                    'colors' => ['B'],
                    'format' => 'commander',
                ], 'call_2'), 200)
                ->push($this->textResponse('I need to repair the list.'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a mono-black Syr Konrad commander deck.',
            ]);

        $response->assertOk();

        $toolMessages = $this->conversation->fresh()->messages()->where('role', 'tool')->get();
        $searchToolPayload = json_decode($toolMessages->last()->content, true);

        $this->assertIsArray($searchToolPayload);
        $this->assertStringContainsString('Repair mode', $searchToolPayload['error'] ?? '');
        $this->assertStringContainsString('short by 36', strtolower($searchToolPayload['error'] ?? ''));
    }

    public function test_commander_deck_rejects_cards_outside_commanders_color_identity(): void
    {
        $this->makeCard('Syr Konrad, the Grim', 'Legendary Creature — Human Knight', 0, priceUsd: 1.50)
            ->update(['color_identity' => ['B']]);
        $this->makeCard('Swamp', 'Basic Land — Swamp', 0, priceUsd: 0.05)
            ->update(['color_identity' => ['B']]);
        $this->makeCard('Lightning Bolt', 'Instant', 0, priceUsd: 1.00)
            ->update(['color_identity' => ['R']]);
        $this->makeCard('Counterspell', 'Instant', 0, priceUsd: 1.00)
            ->update(['color_identity' => ['U']]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('propose_deck', [
                    'deck_name' => 'Syr Konrad Drain',
                    'format' => 'commander',
                    'cards' => [
                        ['card_name' => 'Syr Konrad, the Grim', 'quantity' => 1, 'role' => 'commander'],
                        ['card_name' => 'Swamp', 'quantity' => 36, 'role' => 'land'],
                        ['card_name' => 'Lightning Bolt', 'quantity' => 1, 'role' => 'removal'],
                        ['card_name' => 'Counterspell', 'quantity' => 1, 'role' => 'interaction'],
                    ],
                ], 'call_1'), 200)
                ->push($this->textResponse('I will rebuild this as a legal mono-black list.'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a Syr Konrad commander deck',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('deck_proposal'));
        $this->assertStringContainsString(
            'color identity violation',
            strtolower($response->json('deck_proposal.validation_message'))
        );
        $this->assertStringContainsString(
            'Lightning Bolt',
            $response->json('deck_proposal.validation_message')
        );
        $this->assertStringContainsString(
            'Counterspell',
            $response->json('deck_proposal.validation_message')
        );
    }

    public function test_commander_guide_returns_commander_card_data_even_without_sample_deck(): void
    {
        Card::factory()->create([
            'name' => 'Syr Konrad, the Grim',
            'type_line' => 'Legendary Creature — Human Knight',
            'mana_cost' => '{3}{B}{B}',
            'color_identity' => ['B'],
            'oracle_text' => 'Whenever another creature dies, or a creature card is put into a graveyard from anywhere other than the battlefield, or a creature card leaves your graveyard, Syr Konrad, the Grim deals 1 damage to each opponent.',
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('get_commander_guide', [
                    'commander_name' => 'Syr Konrad, the Grim',
                ], 'call_1'), 200)
                ->push($this->textResponse('I see Syr Konrad is mono-black.'), 200),
            'https://api.scryfall.com/*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a Syr Konrad commander deck under $100',
            ]);

        $response->assertOk();

        $toolMessage = $this->conversation->fresh()->messages()->where('role', 'tool')->first();
        $this->assertNotNull($toolMessage);

        $payload = json_decode($toolMessage->content, true);
        $this->assertFalse($payload['found']);
        $this->assertEquals(['B'], $payload['commander']['color_identity']);
        $this->assertEquals('Syr Konrad, the Grim', $payload['commander']['name']);
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
        $buy = $this->makeCard('Mystic Confluence', 'Instant', 0, priceUsd: 3.00);

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
                    'budget' => 4.00,
                    'added_cards' => [
                        ['card_name' => 'Counterspell', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                    'removed_cards' => [
                        ['card_name' => 'Cancel', 'quantity' => 2, 'role' => 'interaction'],
                    ],
                    'buy_cards' => [
                        ['card_name' => 'Mystic Confluence', 'quantity' => 1, 'priority' => 'must-buy', 'category' => 'upgrade'],
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
        $this->assertCount(1, $proposal['buy_cards']);
        $this->assertNull($proposal['draft_deck_id'] ?? null);
        $this->assertEquals('Counterspell', $proposal['added_cards'][0]['name']);
        $this->assertEquals(2, $proposal['added_cards'][0]['quantity']);
        $this->assertEquals('Cancel', $proposal['removed_cards'][0]['name']);
        $this->assertEquals(2, $proposal['removed_cards'][0]['quantity']);
        $this->assertEquals('Mystic Confluence', $proposal['buy_cards'][0]['name']);
        $this->assertEquals(4.0, $proposal['buy_list']['budget']);
        $this->assertEquals(3.0, $proposal['buy_list']['recommended_total']);
        $this->assertCount(1, $proposal['buy_list']['groups']['must_buy']);
        $this->assertEquals('Mystic Confluence', $proposal['buy_list']['groups']['must_buy'][0]['name']);
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
            'https://api.scryfall.com/*' => Http::response(['object' => 'error', 'code' => 'not_found'], 404),
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
