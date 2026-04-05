<?php

namespace App\Jobs;

use App\Models\Card;
use App\Models\SampleDeck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DispatchEdhrecSampleFetches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    /**
     * @param array<int, string> $archetypes
     */
    public function __construct(
        public readonly string $budgetTier = 'average',
        public readonly array $archetypes = ['generic'],
        public readonly int $limit = 0,
        public readonly bool $fresh = false,
    ) {}

    public function handle(): void
    {
        $archetypes = $this->normalizedArchetypes();

        Log::info('EDHREC sample backfill dispatcher picked up.', [
            'budget_tier' => $this->budgetTier,
            'archetypes' => $archetypes->all(),
            'fresh' => $this->fresh,
            'limit' => $this->limit,
        ]);

        $query = Card::where(function ($q) {
                $q->where('type_line', 'like', '%Legendary Creature%')
                    ->orWhere('type_line', 'like', '%Legendary%Planeswalker%')
                    ->orWhere('oracle_text', 'like', '%can be your commander%');
            })
            ->where("legalities->commander", 'legal')
            ->orderBy('name');

        if ($this->limit > 0) {
            $query->limit($this->limit);
        }

        $commanders = $query->pluck('name');

        if ($commanders->isEmpty()) {
            Log::warning('EDHREC sample backfill skipped: no commander-eligible cards found.');
            return;
        }

        if (! $this->fresh) {
            $existing = SampleDeck::query()
                ->get(['commander_name', 'archetype'])
                ->groupBy('commander_name')
                ->map(fn ($rows) => $rows->pluck('archetype')->unique()->values()->all());

            $commanders = $commanders->filter(function ($name) use ($existing, $archetypes) {
                $stored = collect($existing[$name] ?? []);
                return $archetypes->some(fn ($archetype) => ! $stored->contains($archetype));
            })->values();
        }

        if ($commanders->isEmpty()) {
            Log::info('EDHREC sample backfill skipped: all requested commander/archetype variants already cached.', [
                'budget_tier' => $this->budgetTier,
                'archetypes' => $archetypes->all(),
            ]);
            return;
        }

        $dispatched = 0;
        $commanderCount = $commanders->count();

        foreach ($commanders->values() as $index => $name) {
            $slug = $this->nameToSlug($name);

            foreach ($archetypes as $archetype) {
                FetchCommanderAverageDeck::dispatch(
                    $name,
                    $slug,
                    $this->budgetTier,
                    $archetype === 'generic' ? null : $archetype,
                )->onQueue('edhrec');

                $dispatched++;
            }

            if ((($index + 1) % 100) === 0 || ($index + 1) === $commanderCount) {
                Log::info('EDHREC sample backfill dispatcher progress.', [
                    'processed_commanders' => $index + 1,
                    'total_commanders' => $commanderCount,
                    'jobs_dispatched' => $dispatched,
                    'budget_tier' => $this->budgetTier,
                    'archetypes' => $archetypes->all(),
                ]);
            }
        }

        Log::info('EDHREC sample backfill fan-out dispatched.', [
            'budget_tier' => $this->budgetTier,
            'archetypes' => $archetypes->all(),
            'commanders' => $commanders->count(),
            'jobs' => $dispatched,
            'fresh' => $this->fresh,
            'limit' => $this->limit,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizedArchetypes(): Collection
    {
        return collect($this->archetypes)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->map(fn ($value) => $value === 'generic' ? 'generic' : (in_array($value, FetchCommanderAverageDeck::supportedArchetypes(), true) ? $value : null))
            ->filter()
            ->unique()
            ->values()
            ->whenEmpty(fn (Collection $collection) => $collection->push('generic'));
    }

    private function nameToSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = str_replace("'", '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
