<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use App\Services\ScryfallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DeckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_it_lists_the_users_decks()
    {
        Deck::factory()->count(2)->create(['user_id' => $this->user->id]);
        Deck::factory()->create(); // another user's deck

        $response = $this->actingAs($this->user)->getJson('/api/decks');

        $response->assertStatus(200)->assertJsonCount(2);
    }

    public function test_index_includes_card_count()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();
        $deck->cards()->attach($card->id, ['quantity' => 1, 'is_sideboard' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/decks');

        $response->assertStatus(200)->assertJsonPath('0.cards_count', 1);
    }

    public function test_it_creates_a_deck()
    {
        $response = $this->actingAs($this->user)->postJson('/api/decks', [
            'name' => 'My Deck',
            'format' => 'standard',
            'description' => 'A test deck',
        ]);

        $response->assertStatus(201)->assertJsonPath('name', 'My Deck');
        $this->assertDatabaseHas('decks', ['name' => 'My Deck', 'user_id' => $this->user->id]);
    }

    public function test_store_requires_name()
    {
        $response = $this->actingAs($this->user)->postJson('/api/decks', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_invalid_format()
    {
        $response = $this->actingAs($this->user)->postJson('/api/decks', [
            'name' => 'My Deck',
            'format' => 'invalid-format',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['format']);
    }

    public function test_it_shows_a_deck_with_cards()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();
        $deck->cards()->attach($card->id, ['quantity' => 3, 'is_sideboard' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $deck->id)
            ->assertJsonPath('cards.0.pivot.quantity', 3);
    }

    public function test_show_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(403);
    }

    public function test_it_updates_a_deck()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'name' => 'Old Name']);

        $response = $this->actingAs($this->user)->putJson("/api/decks/{$deck->id}", [
            'name' => 'New Name',
            'format' => 'modern',
        ]);

        $response->assertStatus(200)->assertJsonPath('name', 'New Name');
        $this->assertDatabaseHas('decks', ['id' => $deck->id, 'name' => 'New Name']);
    }

    public function test_update_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();

        $response = $this->actingAs($this->user)->putJson("/api/decks/{$deck->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_it_deletes_a_deck()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/decks/{$deck->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('decks', ['id' => $deck->id]);
    }

    public function test_destroy_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();

        $response = $this->actingAs($this->user)->deleteJson("/api/decks/{$deck->id}");

        $response->assertStatus(403);
    }

    public function test_it_adds_a_card_to_a_deck_by_card_id()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/cards", [
            'card_id' => $card->id,
            'quantity' => 4,
            'is_sideboard' => false,
        ]);

        $response->assertStatus(200)->assertJsonPath('message', 'Card added successfully');
        $this->assertDatabaseHas('deck_cards', ['deck_id' => $deck->id, 'card_id' => $card->id, 'quantity' => 4]);
    }

    public function test_it_adds_a_card_to_a_deck_by_scryfall_id()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $scryfallId = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';

        $cardData = [
            'scryfall_id' => $scryfallId,
            'name' => 'Opt',
            'set_code' => 'M21',
            'set_name' => 'Core Set 2021',
            'collector_number' => '059',
            'rarity' => 'common',
            'mana_cost' => '{U}',
            'color_identity' => ['U'],
            'type_line' => 'Instant',
            'image_uri' => 'https://example.com/opt.jpg',
        ];

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($scryfallId, $cardData) {
            $mock->shouldReceive('findCardById')
                ->with($scryfallId)
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/cards", [
            'scryfall_id' => $scryfallId,
            'quantity' => 2,
        ]);

        $response->assertStatus(200)->assertJsonPath('message', 'Card added successfully');
        $this->assertDatabaseHas('cards', ['scryfall_id' => $scryfallId]);
    }

    public function test_add_card_returns_422_when_card_not_found()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);

        $this->mock(ScryfallService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findCardById')->andThrow(new \RuntimeException('Card not found'));
        });

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/cards", [
            'scryfall_id' => 'non-existent-uuid',
            'quantity' => 1,
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'Card not found');
    }

    public function test_add_card_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/cards", [
            'card_id' => $card->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function test_it_removes_a_card_from_a_deck()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();
        $deck->cards()->attach($card->id, ['quantity' => 1, 'is_sideboard' => false]);

        $response = $this->actingAs($this->user)->deleteJson("/api/decks/{$deck->id}/cards/{$card->id}");

        $response->assertStatus(200)->assertJsonPath('message', 'Card removed successfully');
        $this->assertDatabaseMissing('deck_cards', ['deck_id' => $deck->id, 'card_id' => $card->id]);
    }

    public function test_remove_card_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create();

        $response = $this->actingAs($this->user)->deleteJson("/api/decks/{$deck->id}/cards/{$card->id}");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_users_cannot_access_decks()
    {
        $response = $this->getJson('/api/decks');

        $response->assertStatus(401);
    }
}
