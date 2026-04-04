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

        $response->assertStatus(200)->assertJsonPath('0.cards_sum_quantity', 1);
    }

    public function test_index_includes_owned_and_missing_card_counts()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $ownedCard = Card::factory()->create(['price_usd' => 2.50]);
        $partialCard = Card::factory()->create(['price_usd' => 1.00]);

        $deck->cards()->attach($ownedCard->id, ['quantity' => 2, 'is_sideboard' => false, 'is_commander' => false]);
        $deck->cards()->attach($partialCard->id, ['quantity' => 3, 'is_sideboard' => false, 'is_commander' => false]);

        $this->user->collection()->attach($ownedCard->id, ['quantity' => 2, 'foil' => false]);
        $this->user->collection()->attach($partialCard->id, ['quantity' => 1, 'foil' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/decks');

        $response->assertStatus(200)
            ->assertJsonPath('0.owned_cards_count', 3)
            ->assertJsonPath('0.missing_cards_count', 2)
            ->assertJsonPath('0.total_price', 8)
            ->assertJsonPath('0.missing_price', 2);
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

    public function test_show_includes_owned_and_missing_quantities_for_fully_owned_card()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();

        $deck->cards()->attach($card->id, ['quantity' => 2, 'is_sideboard' => false, 'is_commander' => false]);
        $this->user->collection()->attach($card->id, ['quantity' => 2, 'foil' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(200)
            ->assertJsonPath('cards.0.quantity_required', 2)
            ->assertJsonPath('cards.0.owned_quantity', 2)
            ->assertJsonPath('cards.0.missing_quantity', 0);
    }

    public function test_show_includes_owned_and_missing_quantities_for_partially_owned_card()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();

        $deck->cards()->attach($card->id, ['quantity' => 4, 'is_sideboard' => false, 'is_commander' => false]);
        $this->user->collection()->attach($card->id, ['quantity' => 1, 'foil' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(200)
            ->assertJsonPath('cards.0.quantity_required', 4)
            ->assertJsonPath('cards.0.owned_quantity', 1)
            ->assertJsonPath('cards.0.missing_quantity', 3);
    }

    public function test_show_includes_owned_and_missing_quantities_for_missing_card()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $card = Card::factory()->create();

        $deck->cards()->attach($card->id, ['quantity' => 3, 'is_sideboard' => false, 'is_commander' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(200)
            ->assertJsonPath('cards.0.quantity_required', 3)
            ->assertJsonPath('cards.0.owned_quantity', 0)
            ->assertJsonPath('cards.0.missing_quantity', 3);
    }

    public function test_show_returns_403_for_other_users_deck()
    {
        $deck = Deck::factory()->create();

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}");

        $response->assertStatus(403);
    }

    public function test_buy_list_returns_only_missing_cards_with_estimated_total()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'name' => 'Budget Deck']);
        $ownedCard = Card::factory()->create(['name' => 'Owned Card', 'price_usd' => 2.00]);
        $partialCard = Card::factory()->create(['name' => 'Partial Card', 'price_usd' => 1.50]);
        $missingCard = Card::factory()->create(['name' => 'Missing Card', 'price_usd' => 0.75]);

        $deck->cards()->attach($ownedCard->id, ['quantity' => 2, 'is_sideboard' => false, 'is_commander' => false]);
        $deck->cards()->attach($partialCard->id, ['quantity' => 3, 'is_sideboard' => false, 'is_commander' => false]);
        $deck->cards()->attach($missingCard->id, ['quantity' => 4, 'is_sideboard' => false, 'is_commander' => false]);

        $this->user->collection()->attach($ownedCard->id, ['quantity' => 2, 'foil' => false]);
        $this->user->collection()->attach($partialCard->id, ['quantity' => 1, 'foil' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}/buy-list");

        $response->assertStatus(200)
            ->assertJsonPath('deck_name', 'Budget Deck')
            ->assertJsonPath('missing_cards_count', 6)
            ->assertJsonPath('estimated_total', 6)
            ->assertJsonPath('priced_items_count', 2)
            ->assertJsonPath('unpriced_items_count', 0)
            ->assertJsonCount(2, 'items')
            ->assertJsonFragment([
                'name' => 'Partial Card',
                'missing_quantity' => 2,
                'line_total' => 3,
            ])
            ->assertJsonFragment([
                'name' => 'Missing Card',
                'missing_quantity' => 4,
                'line_total' => 3,
            ]);
    }

    public function test_buy_list_tracks_unpriced_missing_cards()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id]);
        $unpricedCard = Card::factory()->create(['price_usd' => null]);

        $deck->cards()->attach($unpricedCard->id, ['quantity' => 2, 'is_sideboard' => false, 'is_commander' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/decks/{$deck->id}/buy-list");

        $response->assertStatus(200)
            ->assertJsonPath('missing_cards_count', 2)
            ->assertJsonPath('estimated_total', 0)
            ->assertJsonPath('priced_items_count', 0)
            ->assertJsonPath('unpriced_items_count', 1)
            ->assertJsonPath('items.0.missing_quantity', 2)
            ->assertJsonPath('items.0.line_total', null);
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

    // -------------------------------------------------------------------------
    // Draft deck tests
    // -------------------------------------------------------------------------

    public function test_validate_promotes_draft_deck_to_real_deck()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'is_draft' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/validate");

        $response->assertStatus(200)->assertJsonPath('is_draft', false);
        $this->assertDatabaseHas('decks', ['id' => $deck->id, 'is_draft' => false]);
    }

    public function test_validate_returns_403_for_other_users_draft()
    {
        $deck = Deck::factory()->create(['is_draft' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/validate");

        $response->assertStatus(403);
    }

    public function test_validate_also_works_on_non_draft_deck()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'is_draft' => false]);

        $response = $this->actingAs($this->user)->postJson("/api/decks/{$deck->id}/validate");

        $response->assertStatus(200)->assertJsonPath('is_draft', false);
    }

    public function test_draft_deck_is_returned_in_index()
    {
        Deck::factory()->create(['user_id' => $this->user->id, 'is_draft' => false]);
        Deck::factory()->create(['user_id' => $this->user->id, 'is_draft' => true]);

        $response = $this->actingAs($this->user)->getJson('/api/decks');

        $response->assertStatus(200)->assertJsonCount(2);
        $isDrafts = collect($response->json())->pluck('is_draft');
        $this->assertContains(true, $isDrafts);
        $this->assertContains(false, $isDrafts);
    }

    public function test_draft_deck_can_be_deleted()
    {
        $deck = Deck::factory()->create(['user_id' => $this->user->id, 'is_draft' => true]);

        $response = $this->actingAs($this->user)->deleteJson("/api/decks/{$deck->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('decks', ['id' => $deck->id]);
    }

    public function test_unauthenticated_users_cannot_validate_deck()
    {
        $deck = Deck::factory()->create(['is_draft' => true]);

        $response = $this->postJson("/api/decks/{$deck->id}/validate");

        $response->assertStatus(401);
    }
}
