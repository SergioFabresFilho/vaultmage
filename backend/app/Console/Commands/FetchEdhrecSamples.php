<?php

namespace App\Console\Commands;

use App\Jobs\DispatchEdhrecSampleFetches;
use App\Jobs\FetchCommanderAverageDeck;
use App\Models\Card;
use Illuminate\Console\Command;

class FetchEdhrecSamples extends Command
{
    protected $signature = 'decks:fetch-edhrec
                            {--limit=0 : Cap the number of jobs dispatched (0 = no limit, useful for testing)}
                            {--fresh   : Re-fetch all commanders, including ones already stored}
                            {--archetypes=generic : Comma-separated archetypes to fetch, e.g. generic,aristocrats,reanimator}
                            {--all-archetypes : Dispatch every supported archetype variant}';

    protected $description = 'Dispatch jobs to fetch EDHREC average Commander decks for AI training samples.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $fresh = (bool) $this->option('fresh');
        $archetypes = $this->resolveArchetypes();

        DispatchEdhrecSampleFetches::dispatch(
            budgetTier: 'average',
            archetypes: $archetypes->all(),
            limit: $limit,
            fresh: $fresh,
        )->onQueue('edhrec');

        $this->info(sprintf(
            'Queued EDHREC sample backfill orchestration on the [edhrec] queue for archetypes: %s',
            $archetypes->implode(', ')
        ));

        return self::SUCCESS;
    }

    private function resolveArchetypes(): \Illuminate\Support\Collection
    {
        if ((bool) $this->option('all-archetypes')) {
            return collect(array_merge(['generic'], FetchCommanderAverageDeck::supportedArchetypes()));
        }

        $archetypes = collect(explode(',', (string) $this->option('archetypes')))
            ->map(fn ($value) => strtolower(trim($value)))
            ->filter()
            ->values();

        return $archetypes->isEmpty() ? collect(['generic']) : $archetypes;
    }
}
