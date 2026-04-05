<?php

namespace App\Jobs;

use App\Models\CardImportRun;
use App\Services\ScryfallBulkImportService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunScryfallBulkImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 7200;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('scryfall-bulk-import-orchestrator'))
                ->releaseAfter(300)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(ScryfallBulkImportService $importer): void
    {
        $run = CardImportRun::findOrFail($this->runId);

        Log::info('Scryfall bulk import orchestration picked up', [
            'run_id' => $run->id,
            'attempts' => $this->attempts(),
            'bulk_type' => $run->bulk_type,
            'chunk_size' => $run->chunk_size,
            'dry_run' => $run->dry_run,
            'queue' => $this->queue,
        ]);

        if (in_array($run->status, [CardImportRun::STATUS_COMPLETED, CardImportRun::STATUS_FAILED], true)) {
            Log::warning('Scryfall bulk import orchestration skipped terminal run', [
                'run_id' => $run->id,
                'status' => $run->status,
                'attempts' => $this->attempts(),
            ]);

            return;
        }

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

        try {
            $summary = $importer->dispatchChunks($run);
        } catch (Throwable $exception) {
            Log::error('Scryfall bulk import orchestration exception', [
                'run_id' => $run->id,
                'attempts' => $this->attempts(),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ($this->isNonRecoverable($exception)) {
                $this->fail($exception);
                return;
            }

            throw $exception;
        }

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

    public function failed(Throwable $exception): void
    {
        CardImportRun::whereKey($this->runId)->update([
            'status' => CardImportRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('Scryfall bulk import failed during orchestration', [
            'run_id' => $this->runId,
            'attempts' => $this->attempts(),
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => mb_substr($exception->getTraceAsString(), 0, 4000),
        ]);
    }

    private function isNonRecoverable(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Bulk data type [')
            || str_contains($message, 'Unable to create a temporary file');
    }
}
