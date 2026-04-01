<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_deck_has_fillable_attributes()
    {
        $user = User::factory()->create();

        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => 'standard',
            'description' => 'A description',
        ]);

        $this->assertEquals('Test Deck', $deck->name);
        $this->assertEquals('standard', $deck->format);
    }

    public function test_deck_belongs_to_user()
    {
        $deck = Deck::factory()->create();

        $this->assertInstanceOf(User::class, $deck->user);
        $this->assertEquals($deck->user_id, $deck->user->id);
    }

    public function test_deck_has_many_cards()
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create();

        $deck->cards()->attach($card->id, ['quantity' => 4, 'is_sideboard' => false]);

        $this->assertCount(1, $deck->cards);
        $this->assertEquals($card->id, $deck->cards->first()->id);
    }

    public function test_deck_cards_pivot_includes_quantity_and_is_sideboard()
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create();

        $deck->cards()->attach($card->id, ['quantity' => 3, 'is_sideboard' => true]);

        $pivot = $deck->cards->first()->pivot;

        $this->assertEquals(3, $pivot->quantity);
        $this->assertTrue((bool) $pivot->is_sideboard);
    }

    public function test_is_draft_defaults_to_false()
    {
        $user = User::factory()->create();

        $deck = Deck::create([
            'user_id' => $user->id,
            'name'    => 'Test Deck',
        ]);

        $this->assertFalse((bool) $deck->fresh()->is_draft);
    }

    public function test_is_draft_can_be_set_to_true()
    {
        $deck = Deck::factory()->create(['is_draft' => true]);

        $this->assertTrue($deck->is_draft);
    }

    public function test_is_draft_is_cast_to_boolean()
    {
        $deck = Deck::factory()->create(['is_draft' => true]);
        $fresh = $deck->fresh();

        $this->assertIsBool($fresh->is_draft);
    }
}
