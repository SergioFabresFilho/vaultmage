<?php

namespace Tests\Feature;

use App\Models\CardImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardImportStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_command_shows_latest_run(): void
    {
        CardImportRun::create([
            'bulk_type' => 'oracle_cards',
            'chunk_size' => 500,
            'status' => CardImportRun::STATUS_COMPLETED,
            'total_cards' => 100,
            'processed_cards' => 100,
            'total_chunks' => 2,
            'processed_chunks' => 2,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $run = CardImportRun::latest('id')->firstOrFail();

        $this->artisan('cards:import-status')
            ->expectsTable(
                ['ID', 'Status', 'Type', 'Dry Run', 'Cards', 'Chunks', 'Started', 'Finished'],
                [[
                    'ID' => $run->id,
                    'Status' => 'completed',
                    'Type' => 'oracle_cards',
                    'Dry Run' => 'no',
                    'Cards' => '100/100',
                    'Chunks' => '2/2',
                    'Started' => $run->started_at?->toDateTimeString() ?? '-',
                    'Finished' => $run->finished_at?->toDateTimeString() ?? '-',
                ]]
            )
            ->assertSuccessful();
    }

    public function test_status_command_warns_when_no_runs_exist(): void
    {
        $this->artisan('cards:import-status')
            ->expectsOutputToContain('No card import runs found.')
            ->assertSuccessful();
    }
}
