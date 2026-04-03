<?php

namespace App\Jobs;

use App\Models\CardImportRun;
use App\Services\ScryfallBulkImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunScryfallBulkImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function handle(ScryfallBulkImportService $importer): void
    {
        $run = CardImportRun::findOrFail($this->runId);

        $run->update([
            'status' => CardImportRun::STATUS_DOWNLOADING,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
            'finished_at' => null,
        ]);

        Log::info('Scryfall bulk import started', [
            'run_id' => $run->id,
            'bulk_type' => $run->bulk_type,
            'chunk_size' => $run->chunk_size,
            'dry_run' => $run->dry_run,
        ]);

        $summary = $importer->dispatchChunks($run);

        $run->refresh();
        $finalStatus = $summary['total_chunks'] === 0
            ? CardImportRun::STATUS_COMPLETED
            : ($run->processed_chunks >= $summary['total_chunks']
                ? CardImportRun::STATUS_COMPLETED
                : CardImportRun::STATUS_PROCESSING);

        $run->forceFill([
            'status' => $finalStatus,
            'bulk_updated_at' => $summary['bulk_updated_at'],
            'bulk_size_bytes' => $summary['bulk_size_bytes'],
            'total_cards' => $summary['total_cards'],
            'skipped_cards' => $summary['skipped_cards'],
            'total_chunks' => $summary['total_chunks'],
            'finished_at' => $finalStatus === CardImportRun::STATUS_COMPLETED ? now() : null,
        ])->save();

        Log::info('Scryfall bulk import dispatch finished', [
            'run_id' => $run->id,
            'status' => $finalStatus,
            'total_cards' => $summary['total_cards'],
            'skipped_cards' => $summary['skipped_cards'],
            'total_chunks' => $summary['total_chunks'],
            'bulk_size_bytes' => $summary['bulk_size_bytes'],
            'bulk_updated_at' => $summary['bulk_updated_at'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        CardImportRun::whereKey($this->runId)->update([
            'status' => CardImportRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('Scryfall bulk import failed during orchestration', [
            'run_id' => $this->runId,
            'message' => $exception->getMessage(),
        ]);
    }
}
