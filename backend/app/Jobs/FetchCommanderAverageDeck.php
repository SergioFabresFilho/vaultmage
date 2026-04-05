<?php

namespace App\Jobs;

use App\Models\SampleDeck;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchCommanderAverageDeck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    private const AVG_DECK_URL = 'https://json.edhrec.com/pages/average-decks/%s.json';

    /** EDHREC slug suffixes for non-average tiers */
    private const TIER_SUFFIXES = [
        'budget'    => '-budget',
        'expensive' => '-expensive',
        'average'   => '',
    ];

    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer'    => 'https://edhrec.com/',
        'Accept'     => 'application/json, */*',
    ];

    private const SUPPORTED_ARCHETYPES = [
        'aristocrats',
        'aggro',
        'tokens',
        'spellslinger',
        'reanimator',
        'blink',
        'sacrifice',
        'ramp',
        'control',
        'combo',
        'midrange',
    ];

    public function __construct(
        public readonly string $commanderName,
        public readonly string $commanderSlug,
        public readonly string $budgetTier = 'average',
        public readonly ?string $archetype = null,
    ) {}

    public function handle(): void
    {
        // Polite rate limiting — keeps requests under ~3/sec even with multiple workers
        usleep(350_000);

        $client = new GuzzleClient([
            'timeout'         => 20,
            'connect_timeout' => 10,
            'headers'         => self::HEADERS,
        ]);

        try {
            $data = $this->fetchDeckPayload($client);
        } catch (RequestException $e) {
            if ($e->getResponse() && in_array($e->getResponse()->getStatusCode(), [403, 404])) {
                // No EDHREC page for this commander — not worth retrying
                $this->delete();
                return;
            }
            throw $e; // let the queue retry on transient errors
        }

        $cards = $this->parseDeck($data);

        if (count($cards) < 90) {
            // Partial data — skip silently
            $this->delete();
            return;
        }

        SampleDeck::updateOrCreate(
            [
                'commander_slug' => $this->commanderSlug,
                'budget_tier' => $this->budgetTier,
                'archetype' => $this->normalizedArchetype(),
            ],
            [
                'source'         => 'edhrec',
                'format'         => 'commander',
                'commander_name' => $this->commanderName,
                'cards'          => $cards,
                'fetched_at'     => now(),
            ]
        );

        Log::debug('FetchCommanderAverageDeck: saved', [
            'commander'   => $this->commanderName,
            'budget_tier' => $this->budgetTier,
            'archetype'   => $this->normalizedArchetype(),
            'cards'       => count($cards),
        ]);
    }

    private function fetchDeckPayload(GuzzleClient $client): array
    {
        $lastException = null;

        foreach ($this->candidateUrls() as $url) {
            try {
                $resp = $client->get($url);
                return json_decode((string) $resp->getBody(), true) ?? [];
            } catch (RequestException $e) {
                $lastException = $e;
                if (! $e->getResponse() || ! in_array($e->getResponse()->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return [];
    }

    /**
     * EDHREC does not publish a stable public archetype API. Try a few URL shapes
     * observed from the site structure and fall back to the generic average-deck
     * endpoint when no archetype-specific page exists.
     *
     * @return array<int, string>
     */
    private function candidateUrls(): array
    {
        $suffix = self::TIER_SUFFIXES[$this->budgetTier] ?? '';
        $baseSlug = $this->commanderSlug . $suffix;
        $archetype = $this->normalizedArchetype();

        if ($archetype === 'generic') {
            return [sprintf(self::AVG_DECK_URL, $baseSlug)];
        }

        return array_values(array_unique([
            sprintf(self::AVG_DECK_URL, $baseSlug . '/' . $archetype),
            sprintf(self::AVG_DECK_URL, $baseSlug . '-' . $archetype),
            sprintf(self::AVG_DECK_URL, $baseSlug),
        ]));
    }

    private function normalizedArchetype(): string
    {
        $archetype = strtolower(trim((string) $this->archetype));

        if ($archetype === '' || ! in_array($archetype, self::SUPPORTED_ARCHETYPES, true)) {
            return 'generic';
        }

        return $archetype;
    }

    /**
     * Parse [{name, quantity}] from EDHREC's "deck" array of "1 Card Name" strings.
     *
     * @return array<int, array{name: string, quantity: int}>
     */
    private function parseDeck(array $data): array
    {
        $cards = [];

        foreach ($data['deck'] ?? [] as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (! preg_match('/^(\d+)\s+(.+)$/', trim($entry), $m)) {
                continue;
            }
            $cards[] = ['name' => trim($m[2]), 'quantity' => (int) $m[1]];
        }

        return $cards;
    }
}
