<?php

namespace App\Console\Commands;

use App\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class FillCardPrices extends Command
{
    protected $signature = 'cards:fill-prices
                            {--deck-only  : Only fill prices for cards that appear in at least one deck}
                            {--limit=0    : Max cards to process (0 = all)}
                            {--workers=1  : Number of parallel workers}
                            {--id-from=   : Process only cards with id >= this value (set by orchestrator)}
                            {--id-to=     : Process only cards with id <= this value (set by orchestrator)}';

    protected $description = 'Back-fill price_usd from Scryfall for cards that are missing a price.';

    public function handle(): int
    {
        $workers = max(1, (int) $this->option('workers'));
        $idFrom  = $this->option('id-from');

        // Orchestrator mode: no ID range assigned yet — compute ranges and spawn workers.
        if ($workers > 1 && $idFrom === null) {
            return $this->runParallel($workers);
        }

        return $this->runWorker();
    }

    private function runParallel(int $workers): int
    {
        $deckOnly = (bool) $this->option('deck-only');

        $base = Card::whereNull('price_usd');
        if ($deckOnly) {
            $base->whereHas('decks');
        }

        $minId = (int) (clone $base)->min('id');
        $maxId = (int) (clone $base)->max('id');

        if ($minId === 0 && $maxId === 0) {
            $this->info('No cards need price updates.');
            return self::SUCCESS;
        }

        $rangeSize = (int) ceil(($maxId - $minId + 1) / $workers);

        $this->info("Spawning {$workers} parallel workers (IDs {$minId}–{$maxId}, ~{$rangeSize} each)…");

        $baseCmd = [
            PHP_BINARY,
            base_path('artisan'),
            'cards:fill-prices',
            '--no-ansi',
            '--no-interaction',
        ];

        if ($deckOnly) {
            $baseCmd[] = '--deck-only';
        }

        if ((int) $this->option('limit') > 0) {
            $baseCmd[] = '--limit=' . $this->option('limit');
        }

        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $fromId = $minId + $i * $rangeSize;
            $toId   = min($maxId, $fromId + $rangeSize - 1);

            $cmd = array_merge($baseCmd, [
                '--id-from=' . $fromId,
                '--id-to='   . $toId,
            ]);

            $p = new Process($cmd, base_path());
            $p->setTimeout(null);
            $p->start();
            $processes[$i] = $p;
            $this->line("  Worker {$i} started (PID {$p->getPid()}, IDs {$fromId}–{$toId})");
        }

        // Poll all processes so output from every worker is printed in real time.
        while (true) {
            $anyRunning = false;

            foreach ($processes as $i => $p) {
                $out = $p->getIncrementalOutput() . $p->getIncrementalErrorOutput();
                foreach (explode("\n", rtrim($out)) as $line) {
                    if ($line !== '') {
                        $this->line("[worker-{$i}] {$line}");
                    }
                }

                if ($p->isRunning()) {
                    $anyRunning = true;
                }
            }

            if (! $anyRunning) {
                break;
            }

            usleep(100_000); // poll every 100 ms
        }

        $failed = 0;
        foreach ($processes as $i => $p) {
            if (! $p->isSuccessful()) {
                $this->error("Worker {$i} exited with code {$p->getExitCode()}.");
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} worker(s) failed.");
            return self::FAILURE;
        }

        $this->info('All workers finished.');
        return self::SUCCESS;
    }

    private function runWorker(): int
    {
        $deckOnly = (bool) $this->option('deck-only');
        $limit    = (int) $this->option('limit');
        $idFrom   = $this->option('id-from');
        $idTo     = $this->option('id-to');

        $query = Card::whereNull('price_usd');

        if ($deckOnly) {
            $query->whereHas('decks');
        }

        if ($idFrom !== null) {
            $query->where('id', '>=', (int) $idFrom);
        }

        if ($idTo !== null) {
            $query->where('id', '<=', (int) $idTo);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $label = $idFrom !== null ? "IDs {$idFrom}–{$idTo}" : 'all';
        $total = $query->count();

        if ($total === 0) {
            $this->info("No cards need price updates ({$label}).");
            return self::SUCCESS;
        }

        $this->info("Filling prices for {$total} cards ({$label})…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $filled  = 0;
        $missing = 0;

        // 80 ms per request stays under Scryfall's 10 req/s limit regardless of
        // worker count, since each worker is its own OS process with its own timer.
        $query->chunkById(100, function ($cards) use ($bar, &$filled, &$missing) {
            foreach ($cards as $card) {
                $bar->advance();
                usleep(80_000);

                try {
                    $response = Http::baseUrl('https://api.scryfall.com')
                        ->withHeaders([
                            'User-Agent' => 'VaultMage/1.0 (contact@vaultmage.app)',
                            'Accept'     => 'application/json;q=0.9,*/*;q=0.8',
                        ])
                        ->timeout(10)
                        ->get('/cards/named', ['exact' => $card->name]);
                } catch (\Throwable) {
                    $missing++;
                    continue;
                }

                if (! $response->ok()) {
                    $missing++;
                    continue;
                }

                $price = $response->json('prices.usd');
                $card->update(['price_usd' => $price !== null ? (float) $price : null]);

                if ($price !== null) {
                    $filled++;
                } else {
                    $missing++;
                }
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done ({$label}). {$filled} filled, {$missing} have no USD price.");

        return self::SUCCESS;
    }
}
