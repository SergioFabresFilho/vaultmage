<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Re-import the full Scryfall oracle_cards catalog every Sunday at 03:00.
// New MTG sets ship ~quarterly; weekly keeps errata and new promo printings current.
Schedule::command('cards:import-bulk')->weekly()->sundays()->at('03:00');

// Refresh EDHREC average Commander decks every Monday at 04:00 (after Scryfall import settles).
Schedule::command('decks:fetch-edhrec')->weekly()->mondays()->at('04:00');
