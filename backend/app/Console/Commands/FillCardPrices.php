<?php

namespace App\Console\Commands;

use App\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FillCardPrices extends Command
{
    protected $signature = 'cards:fill-prices
                            {--deck-only : Only fill prices for cards that appear in at least one deck}
                            {--limit=0   : Max cards to process (0 = all)}';

    protected $description = 'Back-fill price_usd from Scryfall for cards that are missing a price (uses batch API).';

    private const BATCH_SIZE = 75; // Scryfall /cards/collection limit
    private const DELAY_MS   = 100; // ~10 req/s safe limit

    public function handle(): int
    {
        $deckOnly = (bool) $this->option('deck-only');
        $limit    = (int) $this->option('limit');

        $query = Card::whereNull('price_usd')->whereNotNull('scryfall_id');

        if ($deckOnly) {
            $query->whereHas('decks');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No cards need price updates.');
            return self::SUCCESS;
        }

        $this->info("Filling prices for {$total} cards in batches of " . self::BATCH_SIZE . '…');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $filled  = 0;
        $missing = 0;

        $query->select(['id', 'scryfall_id'])
            ->chunkById(self::BATCH_SIZE, function ($cards) use ($bar, &$filled, &$missing) {
                $identifiers = $cards->map(fn ($c) => ['id' => $c->scryfall_id])->values()->all();

                try {
                    $response = Http::baseUrl('https://api.scryfall.com')
                        ->withHeaders([
                            'User-Agent' => 'VaultMage/1.0 (contact@vaultmage.app)',
                            'Accept'     => 'application/json;q=0.9,*/*;q=0.8',
                        ])
                        ->timeout(30)
                        ->post('/cards/collection', ['identifiers' => $identifiers]);
                } catch (\Throwable) {
                    $missing += count($identifiers);
                    $bar->advance(count($identifiers));
                    return;
                }

                if (! $response->ok()) {
                    $missing += count($identifiers);
                    $bar->advance(count($identifiers));
                    return;
                }

                // Index returned cards by their Scryfall ID for O(1) lookup.
                $priceMap = [];
                foreach ($response->json('data', []) as $scryfallCard) {
                    $priceMap[$scryfallCard['id']] = $scryfallCard['prices']['usd'] ?? null;
                }

                foreach ($cards as $card) {
                    $price = $priceMap[$card->scryfall_id] ?? null;

                    $card->update(['price_usd' => $price !== null ? (float) $price : null]);

                    if ($price !== null) {
                        $filled++;
                    } else {
                        $missing++;
                    }

                    $bar->advance();
                }

                usleep(self::DELAY_MS * 1000);
            });

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$filled} filled, {$missing} have no USD price on Scryfall.");

        return self::SUCCESS;
    }
}
