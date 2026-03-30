<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_has_fillable_attributes()
    {
        $card = Card::create([
            'scryfall_id'      => 'uuid-123',
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ]);

        $this->assertEquals('Opt', $card->name);
        $this->assertEquals(['U'], $card->color_identity);
    }

    public function test_card_casts_color_identity_to_array()
    {
        $card = Card::factory()->create([
            'color_identity' => ['W', 'U']
        ]);

        $this->assertIsArray($card->color_identity);
        $this->assertEquals(['W', 'U'], $card->color_identity);
        
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'color_identity' => json_encode(['W', 'U'])
        ]);
    }

    public function test_card_has_collectors_relationship()
    {
        $card = Card::factory()->create();
        $user = User::factory()->create();

        $card->collectors()->attach($user->id, [
            'quantity' => 4,
            'foil' => true
        ]);

        $this->assertCount(1, $card->collectors);
        $this->assertEquals($user->id, $card->collectors->first()->id);
        $this->assertEquals(4, $card->collectors->first()->pivot->quantity);
        $this->assertTrue((bool)$card->collectors->first()->pivot->foil);
    }
}
