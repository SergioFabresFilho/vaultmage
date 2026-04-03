<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardImportRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'bulk_type',
        'chunk_size',
        'dry_run',
        'status',
        'total_cards',
        'processed_cards',
        'skipped_cards',
        'total_chunks',
        'processed_chunks',
        'bulk_size_bytes',
        'bulk_updated_at',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'bulk_updated_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
