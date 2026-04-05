<?php

namespace App\Services;

use App\Jobs\ProcessScryfallBulkChunk;
use App\Models\CardImportRun;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScryfallBulkImportService
{
    private const SCRYFALL_BULK_URL = 'https://api.scryfall.com/bulk-data';
    private const SKIP_LAYOUTS = ['token', 'emblem', 'art_series', 'double_faced_token'];

    /**
     * @return array{bulk_size_bytes:int, bulk_updated_at:?string, total_cards:int, skipped_cards:int, total_chunks:int}
     */
    public function dispatchChunks(CardImportRun $run): array
    {
        $bulk = $this->fetchBulkMetadata($run->bulk_type);

        Log::info('Scryfall bulk import opening bulk data stream', [
            'run_id' => $run->id,
            'bulk_type' => $run->bulk_type,
            'download_uri' => $bulk['download_uri'],
            'bulk_size_bytes' => $bulk['size'] ?? null,
            'bulk_updated_at' => $bulk['updated_at'] ?? null,
        ]);

        $stream = $this->openBulkStream($bulk);

        try {
            $batch = [];
            $totalCards = 0;
            $skippedCards = 0;
            $totalChunks = 0;
            $now = now()->toDateTimeString();

            foreach ($this->streamJsonObjects($stream) as $raw) {
                if ($this->shouldSkip($raw)) {
                    $skippedCards++;
                    continue;
                }

                $mapped = $this->mapCard($raw, $now);
                if ($mapped === null) {
                    $skippedCards++;
                    continue;
                }

                $batch[] = $mapped;
                $totalCards++;

                if (count($batch) >= $run->chunk_size) {
                    ProcessScryfallBulkChunk::dispatch($run->id, $batch, (bool) $run->dry_run)
                        ->onQueue('cards');
                    $totalChunks++;
                    $batch = [];
                }
            }

            if ($batch !== []) {
                ProcessScryfallBulkChunk::dispatch($run->id, $batch, (bool) $run->dry_run)
                    ->onQueue('cards');
                $totalChunks++;
            }

            Log::info('Scryfall bulk import dispatched chunk jobs', [
                'run_id' => $run->id,
                'total_cards' => $totalCards,
                'skipped_cards' => $skippedCards,
                'total_chunks' => $totalChunks,
                'chunk_size' => $run->chunk_size,
                'dry_run' => $run->dry_run,
            ]);

            return [
                'bulk_size_bytes' => (int) ($bulk['size'] ?? 0),
                'bulk_updated_at' => $bulk['updated_at'] ?? null,
                'total_cards' => $totalCards,
                'skipped_cards' => $skippedCards,
                'total_chunks' => $totalChunks,
            ];
        } finally {
            $this->closeStream($stream);
        }
    }

    /**
     * @return array{download_uri:string, size?:int, updated_at?:string}
     */
    public function fetchBulkMetadata(string $type): array
    {
        $response = Http::withHeaders($this->headers())
            ->withOptions(['version' => 1.1])
            ->timeout(15)
            ->connectTimeout(10)
            ->get(self::SCRYFALL_BULK_URL);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch the Scryfall bulk-data index.');
        }

        $entry = collect($response->json('data', []))
            ->firstWhere('type', $type);

        if (! $entry || empty($entry['download_uri'])) {
            throw new \RuntimeException("Bulk data type [{$type}] was not found on Scryfall.");
        }

        return $entry;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    protected function openBulkStream(array $bulk)
    {
        $client = new GuzzleClient([
            'headers' => $this->headers(),
            'timeout' => 1800,
            'connect_timeout' => 15,
            'http_errors' => false,
            'version' => 1.1,
        ]);

        $response = $client->request('GET', $bulk['download_uri'], [
            'stream' => true,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('Failed to download Scryfall bulk data.');
        }

        return $response->getBody();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamJsonObjects($stream): \Generator
    {
        $readChunk = 65536;
        $objBuf = '';
        $depth = 0;
        $inString = false;
        $escape = false;

        while (! $this->streamIsEof($stream)) {
            $data = $this->readStreamChunk($stream, $readChunk);

            if ($data === '' || $data === false) {
                continue;
            }

            for ($i = 0, $len = strlen($data); $i < $len; $i++) {
                $ch = $data[$i];

                if ($escape) {
                    $escape = false;
                    if ($depth > 0) {
                        $objBuf .= $ch;
                    }
                    continue;
                }

                if ($ch === '\\' && $inString) {
                    $escape = true;
                    if ($depth > 0) {
                        $objBuf .= $ch;
                    }
                    continue;
                }

                if ($ch === '"') {
                    $inString = ! $inString;
                }

                if (! $inString) {
                    if ($ch === '{') {
                        $depth++;
                    } elseif ($ch === '}') {
                        $depth--;
                    }
                }

                if ($depth > 0) {
                    $objBuf .= $ch;
                } elseif ($depth === 0 && $objBuf !== '') {
                    $objBuf .= '}';
                    $decoded = json_decode($objBuf, true);
                    $objBuf = '';

                    if (is_array($decoded)) {
                        yield $decoded;
                    }
                }
            }
        }
    }

    private function readStreamChunk($stream, int $length): string|false
    {
        if (is_resource($stream)) {
            return fread($stream, $length);
        }

        return $stream->read($length);
    }

    private function streamIsEof($stream): bool
    {
        if (is_resource($stream)) {
            return feof($stream);
        }

        return $stream->eof();
    }

    private function closeStream($stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
            return;
        }

        $stream->close();
    }

    private function shouldSkip(array $raw): bool
    {
        if (in_array($raw['layout'] ?? '', self::SKIP_LAYOUTS, true)) {
            return true;
        }

        return ($raw['digital'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapCard(array $raw, string $now): ?array
    {
        $scryfallId = $raw['id'] ?? null;
        $name = $raw['name'] ?? null;

        if (! $scryfallId || ! $name) {
            return null;
        }

        $imageUri = $raw['image_uris']['normal']
            ?? $raw['card_faces'][0]['image_uris']['normal']
            ?? null;

        $oracleText = $raw['oracle_text'] ?? null;
        if ($oracleText === null && ! empty($raw['card_faces'])) {
            $parts = array_map(
                fn ($f) => trim(($f['name'] ?? '').': '.($f['oracle_text'] ?? '')),
                $raw['card_faces']
            );
            $oracleText = implode("\n\n", array_filter($parts)) ?: null;
        }

        return [
            'scryfall_id' => $scryfallId,
            'name' => $name,
            'set_code' => strtoupper($raw['set'] ?? ''),
            'set_name' => $raw['set_name'] ?? '',
            'collector_number' => $raw['collector_number'] ?? '',
            'rarity' => $raw['rarity'] ?? 'common',
            'mana_cost' => $raw['mana_cost'] ?? null,
            'oracle_text' => $oracleText ? mb_substr($oracleText, 0, 1000) : null,
            'cmc' => $raw['cmc'] ?? null,
            'color_identity' => json_encode($raw['color_identity'] ?? []),
            'legalities' => json_encode($raw['legalities'] ?? []),
            'type_line' => $raw['type_line'] ?? '',
            'image_uri' => $imageUri,
            'price_usd' => isset($raw['prices']['usd']) ? (float) $raw['prices']['usd'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'VaultMage/1.0',
            'Accept' => 'application/json',
        ];
    }
}
