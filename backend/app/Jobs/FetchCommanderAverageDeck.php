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

    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer'    => 'https://edhrec.com/',
        'Accept'     => 'application/json, */*',
    ];

    public function __construct(
        public readonly string $commanderName,
        public readonly string $commanderSlug,
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
            $url  = sprintf(self::AVG_DECK_URL, $this->commanderSlug);
            $resp = $client->get($url);
            $data = json_decode((string) $resp->getBody(), true);
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
            ['commander_slug' => $this->commanderSlug],
            [
                'source'         => 'edhrec',
                'format'         => 'commander',
                'commander_name' => $this->commanderName,
                'cards'          => $cards,
                'fetched_at'     => now(),
            ]
        );

        Log::debug('FetchCommanderAverageDeck: saved', [
            'commander' => $this->commanderName,
            'cards'     => count($cards),
        ]);
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
