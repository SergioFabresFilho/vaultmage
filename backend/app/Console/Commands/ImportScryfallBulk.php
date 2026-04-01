<?php

namespace App\Console\Commands;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportScryfallBulk extends Command
{
    protected $signature = 'cards:import-bulk
                            {--type=oracle_cards : Bulk data type to import (oracle_cards or default_cards)}
                            {--chunk=500 : Number of cards to upsert per batch}
                            {--dry-run : Parse and count without writing to the database}';

    protected $description = 'Import the full MTG card catalog from Scryfall bulk data (oracle_cards by default).';

    private const SCRYFALL_BULK_URL = 'https://api.scryfall.com/bulk-data';
    private const SKIP_LAYOUTS      = ['token', 'emblem', 'art_series', 'double_faced_token'];

    public function handle(): int
    {
        set_time_limit(0);

        $type   = $this->option('type');
        $chunk  = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        // ── 1. Resolve download URL ────────────────────────────────────────────

        $this->info("Fetching Scryfall bulk-data index…");

        $client = new GuzzleClient();

        try {
            $bulkRes = $client->get(self::SCRYFALL_BULK_URL, [
                'headers'         => ['User-Agent' => 'VaultMage/1.0', 'Accept' => 'application/json'],
                'version'         => 1.1,
                'timeout'         => 15,
                'connect_timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to fetch bulk-data index: " . $e->getMessage());
            return self::FAILURE;
        }

        $bulkEntries = collect(json_decode($bulkRes->getBody()->getContents(), true)['data'] ?? []);
        $entry       = $bulkEntries->firstWhere('type', $type);

        if (! $entry) {
            $this->error("Bulk data type '{$type}' not found. Available: " . $bulkEntries->pluck('type')->join(', '));
            return self::FAILURE;
        }

        $downloadUrl = $entry['download_uri'];
        $sizeBytes   = $entry['size'] ?? 0;
        $sizeMb      = $sizeBytes ? round($sizeBytes / 1024 / 1024, 1) : '?';
        $updatedAt   = $entry['updated_at'] ?? 'unknown';

        $this->info("Bulk file: {$type}  |  {$sizeMb} MB  |  Updated: {$updatedAt}");

        if ($dryRun) {
            $this->warn('[DRY RUN] No data will be written.');
        }

        // ── 2. Stream to a temp file ───────────────────────────────────────────

        $tmpFile = tempnam(sys_get_temp_dir(), 'scryfall_bulk_');
        $this->info("Downloading…");

        try {
            $client->get($downloadUrl, [
                'headers'         => ['User-Agent' => 'VaultMage/1.0', 'Accept' => 'application/json'],
                'version'         => 1.1,
                'sink'            => $tmpFile,
                'timeout'         => 600,
                'connect_timeout' => 15,
            ]);
        } catch (\Throwable $e) {
            $this->error("Download failed: " . $e->getMessage());
            @unlink($tmpFile);
            return self::FAILURE;
        }

        $actualMb = round(filesize($tmpFile) / 1024 / 1024, 1);
        $this->info("Downloaded {$actualMb} MB. Parsing…");

        // ── 3. Stream-parse JSON objects one at a time ─────────────────────────
        //
        // The bulk file is a JSON array: [ {...}, {...}, ... ]
        // We scan byte-by-byte (buffered) tracking brace/string state so we can
        // extract each top-level object without loading the entire file into RAM.

        $fh = fopen($tmpFile, 'rb');
        if (! $fh) {
            $this->error("Cannot open temp file for reading.");
            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($sizeBytes ?: 1);
        $bar->setFormat(" %current% / %max% bytes [%bar%] %percent:3s%%  %elapsed:6s%");
        $bar->start();

        $batch    = [];
        $imported = 0;
        $skipped  = 0;
        $now      = now()->toDateTimeString();

        foreach ($this->streamJsonObjects($fh, $bar) as $raw) {
            // Skip non-playable layouts
            if (in_array($raw['layout'] ?? '', self::SKIP_LAYOUTS, true)) {
                $skipped++;
                continue;
            }

            // Skip digital-only cards
            if (($raw['digital'] ?? false) === true) {
                $skipped++;
                continue;
            }

            $mapped = $this->mapCard($raw, $now);
            if ($mapped === null) {
                $skipped++;
                continue;
            }

            $batch[] = $mapped;

            if (count($batch) >= $chunk) {
                if (! $dryRun) {
                    $this->upsertBatch($batch);
                }
                $imported += count($batch);
                $batch = [];
            }
        }

        fclose($fh);
        @unlink($tmpFile);

        if (! empty($batch)) {
            if (! $dryRun) {
                $this->upsertBatch($batch);
            }
            $imported += count($batch);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            "Done.  %s cards %s  |  %s skipped.",
            number_format($imported),
            $dryRun ? 'would be imported' : 'imported/updated',
            number_format($skipped),
        ));

        return self::SUCCESS;
    }

    // ── Streaming JSON object extractor ───────────────────────────────────────
    //
    // Yields one decoded card array per top-level JSON object in the array.
    // Advances the progress bar as bytes are consumed.

    private function streamJsonObjects($fh, $bar): \Generator
    {
        $readChunk = 65536; // 64 KB read buffer
        $objBuf    = '';
        $depth     = 0;       // brace nesting depth (we only care about top-level {})
        $inString  = false;   // are we inside a JSON string?
        $escape    = false;   // was the previous char a backslash?
        $bytesRead = 0;

        while (! feof($fh)) {
            $data      = fread($fh, $readChunk);
            $bytesRead += strlen($data);
            $bar->setProgress($bytesRead);

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
                    // Closing brace just consumed — $objBuf is now a complete object
                    $objBuf .= '}';
                    $decoded = json_decode($objBuf, true);
                    $objBuf  = '';
                    if (is_array($decoded)) {
                        yield $decoded;
                    }
                }
            }
        }
    }

    // ── Card mapper ───────────────────────────────────────────────────────────

    private function mapCard(array $raw, string $now): ?array
    {
        $scryfallId = $raw['id'] ?? null;
        $name       = $raw['name'] ?? null;

        if (! $scryfallId || ! $name) {
            return null;
        }

        $imageUri = $raw['image_uris']['normal']
            ?? $raw['card_faces'][0]['image_uris']['normal']
            ?? null;

        $oracleText = $raw['oracle_text'] ?? null;
        if ($oracleText === null && ! empty($raw['card_faces'])) {
            $parts = array_map(
                fn ($f) => trim(($f['name'] ?? '') . ': ' . ($f['oracle_text'] ?? '')),
                $raw['card_faces']
            );
            $oracleText = implode("\n\n", array_filter($parts)) ?: null;
        }

        return [
            'scryfall_id'      => $scryfallId,
            'name'             => $name,
            'set_code'         => strtoupper($raw['set'] ?? ''),
            'set_name'         => $raw['set_name'] ?? '',
            'collector_number' => $raw['collector_number'] ?? '',
            'rarity'           => $raw['rarity'] ?? 'common',
            'mana_cost'        => $raw['mana_cost'] ?? null,
            'oracle_text'      => $oracleText ? mb_substr($oracleText, 0, 1000) : null,
            'cmc'              => $raw['cmc'] ?? null,
            'color_identity'   => json_encode($raw['color_identity'] ?? []),
            'legalities'       => json_encode($raw['legalities'] ?? []),
            'type_line'        => $raw['type_line'] ?? '',
            'image_uri'        => $imageUri,
            'price_usd'        => isset($raw['prices']['usd']) ? (float) $raw['prices']['usd'] : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    // ── DB upsert ─────────────────────────────────────────────────────────────

    private function upsertBatch(array $batch): void
    {
        DB::table('cards')->upsert(
            $batch,
            ['scryfall_id'],
            [
                'name', 'set_code', 'set_name', 'collector_number',
                'rarity', 'mana_cost', 'oracle_text', 'cmc',
                'color_identity', 'legalities', 'type_line', 'image_uri',
                'price_usd', 'updated_at',
            ]
        );
    }
}
