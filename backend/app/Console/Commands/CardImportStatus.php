<?php

namespace App\Console\Commands;

use App\Models\CardImportRun;
use Illuminate\Console\Command;

class CardImportStatus extends Command
{
    protected $signature = 'cards:import-status
                            {--all : Show the latest 10 runs instead of only the most recent run}';

    protected $description = 'Show the status of queued Scryfall card import runs.';

    public function handle(): int
    {
        $query = CardImportRun::query()->latest('id');

        $runs = $this->option('all')
            ? $query->limit(10)->get()
            : $query->limit(1)->get();

        if ($runs->isEmpty()) {
            $this->warn('No card import runs found.');
            return self::SUCCESS;
        }

        $rows = $runs->map(function (CardImportRun $run) {
            $totalCards = $run->total_cards > 0 ? number_format($run->total_cards) : '-';
            $processedCards = number_format($run->processed_cards);
            $totalChunks = $run->total_chunks > 0 ? number_format($run->total_chunks) : '-';
            $processedChunks = number_format($run->processed_chunks);

            return [
                'ID' => $run->id,
                'Status' => $run->status,
                'Type' => $run->bulk_type,
                'Dry Run' => $run->dry_run ? 'yes' : 'no',
                'Cards' => "{$processedCards}/{$totalCards}",
                'Chunks' => "{$processedChunks}/{$totalChunks}",
                'Started' => optional($run->started_at)?->toDateTimeString() ?? '-',
                'Finished' => optional($run->finished_at)?->toDateTimeString() ?? '-',
            ];
        })->all();

        $this->table(
            ['ID', 'Status', 'Type', 'Dry Run', 'Cards', 'Chunks', 'Started', 'Finished'],
            $rows
        );

        $latest = $runs->first();

        if ($latest?->error_message) {
            $this->newLine();
            $this->error("Latest run error: {$latest->error_message}");
        }

        return self::SUCCESS;
    }
}
