<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SampleDeck extends Model
{
    protected $fillable = [
        'source',
        'format',
        'commander_name',
        'commander_slug',
        'budget_tier',
        'archetype',
        'cards',
        'fetched_at',
    ];

    protected $casts = [
        'cards'      => 'array',
        'fetched_at' => 'datetime',
    ];
}
