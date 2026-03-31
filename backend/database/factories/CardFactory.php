<?php

namespace Database\Factories;

use App\Models\Card;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Card>
 */
class CardFactory extends Factory
{
    protected $model = Card::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scryfall_id'      => $this->faker->uuid(),
            'name'             => $this->faker->name(),
            'set_code'         => strtoupper($this->faker->lexify('???')),
            'set_name'         => $this->faker->sentence(2),
            'collector_number' => (string) $this->faker->numberBetween(1, 300),
            'rarity'           => $this->faker->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'mana_cost'        => '{1}{U}',
            'oracle_text'      => 'Draw a card.',
            'cmc'              => 2,
            'color_identity'   => ['U'],
            'legalities'       => ['standard' => 'legal', 'commander' => 'legal', 'brawl' => 'legal'],
            'type_line'        => 'Instant',
            'image_uri'        => $this->faker->imageUrl(),
        ];
    }
}
