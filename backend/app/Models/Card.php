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
    ];

    protected $casts = [
        'cmc' => 'float',
        'color_identity' => 'array',
        'legalities' => 'array',
    ];

    public function collectors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collection_cards')
            ->withPivot(['quantity', 'foil'])
            ->withTimestamps();
    }
}
