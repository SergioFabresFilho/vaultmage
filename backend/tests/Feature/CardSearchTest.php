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

        Card::create([
            'scryfall_id' => 'local-lotus',
            'name' => 'Black Lotus',
            'set_code' => 'lea',
            'set_name' => 'Limited Edition Alpha',
            'collector_number' => '233',
            'rarity' => 'rare',
            'mana_cost' => '{0}',
            'color_identity' => [],
            'type_line' => 'Artifact',
            'image_uri' => 'https://example.com/local-lotus.jpg',
        ]);

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
            ->assertJsonCount(2)
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

    public function test_it_returns_empty_array_for_empty_query()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/cards/search?q=');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_can_filter_commander_search_results(): void
    {
        $user = User::factory()->create();

        Card::create([
            'scryfall_id' => 'atraxa-local',
            'name' => 'Atraxa, Praetors\' Voice',
            'set_code' => 'c16',
            'set_name' => 'Commander 2016',
            'collector_number' => '28',
            'rarity' => 'mythic',
            'mana_cost' => '{1}{G}{W}{U}{B}',
            'type_line' => 'Legendary Creature — Phyrexian Angel Horror',
            'oracle_text' => 'Flying, vigilance, deathtouch, lifelink',
            'color_identity' => ['G', 'W', 'U', 'B'],
            'legalities' => ['commander' => 'legal'],
            'image_uri' => null,
        ]);

        Card::create([
            'scryfall_id' => 'bird-local',
            'name' => 'Birds of Paradise',
            'set_code' => 'lea',
            'set_name' => 'Alpha',
            'collector_number' => '186',
            'rarity' => 'rare',
            'mana_cost' => '{G}',
            'type_line' => 'Creature — Bird',
            'oracle_text' => 'Flying',
            'color_identity' => ['G'],
            'legalities' => ['commander' => 'legal'],
            'image_uri' => null,
        ]);

        $this->mock(ScryfallService::class, function (MockInterface $mock) {
            $mock->shouldReceive('search')
                ->with('Atraxa is:commander legal:commander')
                ->once()
                ->andReturn([]);
        });

        $response = $this->actingAs($user)
            ->getJson('/api/cards/search?q=Atraxa&format=commander&commander_only=1');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Atraxa, Praetors\' Voice'])
            ->assertJsonMissing(['name' => 'Birds of Paradise']);
    }
}
