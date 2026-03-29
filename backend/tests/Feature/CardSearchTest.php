<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\ScryfallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CardSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_search_all_cards_proxies_scryfall()
    {
        $user = User::factory()->create();

        $this->mock(ScryfallService::class, function (MockInterface $mock) {
            $mock->shouldReceive('search')
                ->with('Black Lotus')
                ->once()
                ->andReturn([
                    [
                        'scryfall_id' => 'abc-123',
                        'name' => 'Black Lotus',
                        'set_code' => 'lea',
                        'set_name' => 'Limited Edition Alpha',
                        'collector_number' => '232',
                        'rarity' => 'rare',
                        'mana_cost' => '{0}',
                        'type_line' => 'Artifact',
                        'image_uri' => 'https://example.com/lotus.jpg',
                    ]
                ]);
        });

        $response = $this->actingAs($user)
            ->getJson('/api/cards/search?q=Black+Lotus');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Black Lotus']);
    }

    public function test_can_search_user_collection()
    {
        $user = User::factory()->create();
        $card1 = Card::create([
            'scryfall_id' => 'id-1',
            'name' => 'Lightning Bolt',
            'set_code' => 'lea',
            'set_name' => 'Alpha',
            'collector_number' => '1',
            'rarity' => 'common',
            'mana_cost' => '{R}',
            'color_identity' => ['R'],
            'type_line' => 'Instant',
            'image_uri' => null,
        ]);
        $card2 = Card::create([
            'scryfall_id' => 'id-2',
            'name' => 'Counterspell',
            'set_code' => 'lea',
            'set_name' => 'Alpha',
            'collector_number' => '2',
            'rarity' => 'common',
            'mana_cost' => '{U}{U}',
            'color_identity' => ['U'],
            'type_line' => 'Instant',
            'image_uri' => null,
        ]);

        $user->collection()->attach($card1->id, ['quantity' => 1, 'foil' => false]);
        $user->collection()->attach($card2->id, ['quantity' => 1, 'foil' => false]);

        $response = $this->actingAs($user)
            ->getJson('/api/collection/search?q=Lightning');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Lightning Bolt'])
            ->assertJsonMissing(['name' => 'Counterspell']);
    }
}
