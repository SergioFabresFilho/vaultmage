<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'scryfall_id',
        'name',
        'set_code',
        'set_name',
        'collector_number',
        'rarity',
        'mana_cost',
        'oracle_text',
        'cmc',
        'color_identity',
        'legalities',
        'type_line',
        'image_uri',
        'price_usd',
    ];

    protected $casts = [
        'cmc'            => 'float',
        'price_usd'      => 'float',
        'color_identity' => 'array',
        'legalities'     => 'array',
    ];

    public function decks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'deck_cards')
            ->withPivot(['quantity', 'is_sideboard']);
    }

    public function collectors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collection_cards')
            ->withPivot(['quantity', 'foil'])
            ->withTimestamps();
    }
}
