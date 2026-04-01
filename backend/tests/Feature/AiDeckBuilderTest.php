<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Conversation;
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
