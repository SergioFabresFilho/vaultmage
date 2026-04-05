<?php

namespace Tests\Feature;

use App\Jobs\ProcessScryfallBulkChunk;
use App\Models\CardImportRun;
use App\Services\ScryfallBulkImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScryfallBulkImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_chunks_streams_bulk_data_without_temp_file_download(): void
    {
        Queue::fake();

        $run = CardImportRun::create([
            'bulk_type' => 'oracle_cards',
            'chunk_size' => 2,
            'status' => CardImportRun::STATUS_DOWNLOADING,
            'started_at' => now(),
        ]);

        $service = new class extends ScryfallBulkImportService
        {
            public function fetchBulkMetadata(string $type): array
            {
                return [
                    'download_uri' => 'https://data.scryfall.test/oracle_cards.json',
                    'size' => 321,
                    'updated_at' => '2026-04-05T00:00:00+00:00',
                ];
            }

            protected function openBulkStream(array $bulk)
            {
                $stream = fopen('php://temp', 'rb+');

                fwrite($stream, json_encode([
                    [
                        'id' => 'card-1',
                        'name' => 'Opt',
                        'set' => 'm21',
                        'set_name' => 'Core Set 2021',
                        'collector_number' => '59',
                        'rarity' => 'common',
                        'mana_cost' => '{U}',
                        'oracle_text' => 'Scry 1.',
                        'cmc' => 1,
                        'color_identity' => ['U'],
                        'legalities' => ['commander' => 'legal'],
                        'type_line' => 'Instant',
                        'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
                        'prices' => ['usd' => '0.12'],
                    ],
                    [
                        'id' => 'card-2',
                        'name' => 'Ponder',
                        'set' => 'm12',
                        'set_name' => 'Magic 2012',
                        'collector_number' => '72',
                        'rarity' => 'common',
                        'mana_cost' => '{U}',
                        'oracle_text' => 'Look at the top three cards.',
                        'cmc' => 1,
                        'color_identity' => ['U'],
                        'legalities' => ['commander' => 'legal'],
                        'type_line' => 'Sorcery',
                        'image_uris' => ['normal' => 'https://example.com/ponder.jpg'],
                        'prices' => ['usd' => '1.23'],
                    ],
                    [
                        'id' => 'card-3',
                        'name' => 'Treasure Token',
                        'layout' => 'token',
                        'digital' => false,
                    ],
                ]));

                rewind($stream);

                return $stream;
            }
        };

        $summary = $service->dispatchChunks($run);

        $this->assertSame(2, $summary['total_cards']);
        $this->assertSame(1, $summary['skipped_cards']);
        $this->assertSame(1, $summary['total_chunks']);
        $this->assertSame(321, $summary['bulk_size_bytes']);

        Queue::assertPushedOn('cards', ProcessScryfallBulkChunk::class, function (ProcessScryfallBulkChunk $job) use ($run) {
            return $job->runId === $run->id
                && count($job->cards) === 2
                && $job->cards[0]['name'] === 'Opt'
                && $job->cards[1]['name'] === 'Ponder';
        });
    }
}
