<?php

namespace App\Console\Commands;

use App\Jobs\FetchCommanderAverageDeck;
use App\Models\Card;
use Illuminate\Console\Command;

class FetchEdhrecSamples extends Command
{
    protected $signature = 'decks:fetch-edhrec
                            {--limit=0 : Cap the number of jobs dispatched (0 = no limit, useful for testing)}
                            {--fresh   : Re-fetch all commanders, including ones already stored}
                            {--archetypes=generic : Comma-separated archetypes to fetch, e.g. generic,aristocrats,reanimator}';

    protected $description = 'Dispatch jobs to fetch EDHREC average Commander decks for AI training samples.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $fresh = (bool) $this->option('fresh');
        $archetypes = collect(explode(',', (string) $this->option('archetypes')))
            ->map(fn ($value) => strtolower(trim($value)))
            ->filter()
            ->values();

        if ($archetypes->isEmpty()) {
            $archetypes = collect(['generic']);
        }

        $query = Card::where(function ($q) {
                $q->where('type_line', 'like', '%Legendary Creature%')
                  ->orWhere('type_line', 'like', '%Legendary%Planeswalker%')
                  ->orWhere('oracle_text', 'like', '%can be your commander%');
            })
            ->where("legalities->commander", 'legal')
            ->orderBy('name');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $commanders = $query->pluck('name');

        if ($commanders->isEmpty()) {
            $this->error('No commander-eligible cards found in local database. Run cards:import-bulk first.');
            return self::FAILURE;
        }

        // When not doing a fresh run, skip commanders we already have data for
        if (! $fresh) {
            $existing = \App\Models\SampleDeck::query()
                ->get(['commander_name', 'archetype'])
                ->groupBy('commander_name')
                ->map(fn ($rows) => $rows->pluck('archetype')->unique()->values()->all());

            $commanders = $commanders->filter(function ($name) use ($existing, $archetypes) {
                $stored = collect($existing[$name] ?? []);
                return $archetypes->some(fn ($archetype) => ! $stored->contains($archetype));
            });
        }

        if ($commanders->isEmpty()) {
            $this->info('All commanders already fetched. Use --fresh to re-fetch everything.');
            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($commanders as $name) {
            $slug = $this->nameToSlug($name);
            foreach ($archetypes as $archetype) {
                FetchCommanderAverageDeck::dispatch($name, $slug, 'average', $archetype === 'generic' ? null : $archetype)
                    ->onQueue('edhrec');
                $dispatched++;
            }
        }

        $this->info(sprintf('Dispatched %d jobs on the [edhrec] queue.', $dispatched));

        return self::SUCCESS;
    }

    private function nameToSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = str_replace("'", '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
