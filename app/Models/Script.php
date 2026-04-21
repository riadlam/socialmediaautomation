<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Script extends Model
{
    protected $fillable = [
        'script',
        'status',
        'start_date',
        'finish_date',
        'heygen_session_id',
        'video_id',
        'video_url',
        'poll_attempts',
        'last_polled_at',
        'error',
        'publish_response',
    ];

    protected $casts = [
        'publish_response' => 'array',
        'start_date' => 'datetime',
        'finish_date' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(ScriptLog::class);
    }
}

