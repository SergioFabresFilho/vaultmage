<?php

namespace App\Console\Commands;

use App\Jobs\RunScryfallBulkImport;
use App\Models\CardImportRun;
use Illuminate\Console\Command;

class ImportScryfallBulk extends Command
{
    protected $signature = 'cards:import-bulk
                            {--type=oracle_cards : Bulk data type to import (oracle_cards or default_cards)}
                            {--chunk=500 : Number of cards to upsert per queued chunk}
                            {--dry-run : Parse and count without writing to the database}';

    protected $description = 'Queue a full MTG card catalog import from Scryfall bulk data.';

    public function handle(): int
    {
        $type = (string) $this->option('type');
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $run = CardImportRun::create([
            'bulk_type' => $type,
            'chunk_size' => $chunk,
            'dry_run' => $dryRun,
            'status' => CardImportRun::STATUS_QUEUED,
        ]);

        RunScryfallBulkImport::dispatch($run->id)
            ->onQueue('cards');

        $this->info(sprintf(
            'Queued Scryfall bulk import run #%d on the [cards] queue (%s, chunk=%d%s).',
            $run->id,
            $type,
            $chunk,
            $dryRun ? ', dry-run' : '',
        ));

        $this->line('Monitor progress in the card_import_runs table or via your queue worker logs.');

        return self::SUCCESS;
    }
}
