<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_call_id',
        'tool_calls',
        'metadata',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'metadata'   => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
