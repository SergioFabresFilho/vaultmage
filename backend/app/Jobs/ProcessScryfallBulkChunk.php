<?php

namespace App\Jobs;

use App\Models\CardImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessScryfallBulkChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $cards,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(): void
    {
        $run = CardImportRun::findOrFail($this->runId);

        if (! $this->dryRun && count($this->cards) > 0) {
            DB::table('cards')->upsert(
                $this->cards,
                ['scryfall_id'],
                [
                    'name', 'set_code', 'set_name', 'collector_number',
                    'rarity', 'mana_cost', 'oracle_text', 'cmc',
                    'color_identity', 'legalities', 'type_line', 'image_uri',
                    'price_usd', 'updated_at',
                ]
            );
        }

        $updated = CardImportRun::query()
            ->whereKey($run->id)
            ->update([
                'processed_cards' => DB::raw('processed_cards + '.count($this->cards)),
                'processed_chunks' => DB::raw('processed_chunks + 1'),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        $run->refresh();

        $shouldLogProgress = $run->processed_chunks === 1
            || $run->processed_chunks === $run->total_chunks
            || $run->processed_chunks % 25 === 0;

        if ($shouldLogProgress) {
            Log::info('Scryfall bulk import progress', [
                'run_id' => $run->id,
                'processed_chunks' => $run->processed_chunks,
                'total_chunks' => $run->total_chunks,
                'processed_cards' => $run->processed_cards,
                'total_cards' => $run->total_cards,
                'dry_run' => $this->dryRun,
            ]);
        }

        if (
            $run->status === CardImportRun::STATUS_PROCESSING
            && $run->processed_chunks >= $run->total_chunks
        ) {
            $run->update([
                'status' => CardImportRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ]);

            Log::info('Scryfall bulk import completed', [
                'run_id' => $run->id,
                'processed_chunks' => $run->processed_chunks,
                'total_chunks' => $run->total_chunks,
                'processed_cards' => $run->processed_cards,
                'total_cards' => $run->total_cards,
                'skipped_cards' => $run->skipped_cards,
                'dry_run' => $this->dryRun,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        CardImportRun::whereKey($this->runId)->update([
            'status' => CardImportRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);

        Log::error('Scryfall bulk import chunk failed', [
            'run_id' => $this->runId,
            'chunk_size' => count($this->cards),
            'message' => $exception->getMessage(),
        ]);
    }
}
