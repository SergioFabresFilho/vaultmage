<?php

namespace Tests\Feature;

use App\Jobs\RunScryfallBulkImport;
use App\Models\CardImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CardImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_command_creates_run_and_dispatches_background_job(): void
    {
        Queue::fake();

        $this->artisan('cards:import-bulk', [
            '--type' => 'default_cards',
            '--chunk' => 250,
            '--dry-run' => true,
        ])->assertSuccessful();

        $run = CardImportRun::first();

        $this->assertNotNull($run);
        $this->assertSame('default_cards', $run->bulk_type);
        $this->assertSame(250, $run->chunk_size);
        $this->assertTrue($run->dry_run);
        $this->assertSame(CardImportRun::STATUS_QUEUED, $run->status);

        Queue::assertPushedOn('cards', RunScryfallBulkImport::class, function (RunScryfallBulkImport $job) use ($run) {
            return $job->runId === $run->id;
        });
    }

    public function test_import_command_rejects_new_run_when_another_import_is_active(): void
    {
        Queue::fake();

        CardImportRun::create([
            'bulk_type' => 'oracle_cards',
            'chunk_size' => 500,
            'dry_run' => false,
            'status' => CardImportRun::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        $this->artisan('cards:import-bulk')
            ->assertFailed();

        $this->assertSame(1, CardImportRun::count());
        Queue::assertNothingPushed();
    }
}
