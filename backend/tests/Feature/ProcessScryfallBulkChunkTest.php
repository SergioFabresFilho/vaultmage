<?php

namespace Tests\Feature;

use App\Jobs\ProcessScryfallBulkChunk;
use App\Models\Card;
use App\Models\CardImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessScryfallBulkChunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_job_upserts_cards_and_marks_run_complete(): void
    {
        $run = CardImportRun::create([
            'bulk_type' => 'oracle_cards',
            'chunk_size' => 2,
            'status' => CardImportRun::STATUS_PROCESSING,
            'total_chunks' => 1,
            'total_cards' => 2,
            'started_at' => now(),
        ]);

        $now = now()->toDateTimeString();

        $job = new ProcessScryfallBulkChunk($run->id, [
            [
                'scryfall_id' => 'card-1',
                'name' => 'Opt',
                'set_code' => 'M21',
                'set_name' => 'Core Set 2021',
                'collector_number' => '59',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'oracle_text' => 'Scry 1.',
                'cmc' => 1,
                'color_identity' => json_encode(['U']),
                'legalities' => json_encode(['modern' => 'legal']),
                'type_line' => 'Instant',
                'image_uri' => 'https://example.com/opt.jpg',
                'price_usd' => 0.12,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scryfall_id' => 'card-2',
                'name' => 'Ponder',
                'set_code' => 'M12',
                'set_name' => 'Magic 2012',
                'collector_number' => '72',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'oracle_text' => 'Look at the top three cards.',
                'cmc' => 1,
                'color_identity' => json_encode(['U']),
                'legalities' => json_encode(['modern' => 'legal']),
                'type_line' => 'Sorcery',
                'image_uri' => 'https://example.com/ponder.jpg',
                'price_usd' => 1.23,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $job->handle();

        $run->refresh();

        $this->assertSame(CardImportRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->processed_cards);
        $this->assertSame(1, $run->processed_chunks);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseHas('cards', ['scryfall_id' => 'card-1', 'name' => 'Opt']);
        $this->assertDatabaseHas('cards', ['scryfall_id' => 'card-2', 'name' => 'Ponder']);
        $this->assertSame(2, Card::count());
    }
}
