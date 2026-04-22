<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Script extends Model
{
    protected $fillable = [
        'script',
        'caption',
        'hashtags',
        'status',
        'start_date',
        'finish_date',
        'heygen_session_id',
        'video_id',
        'video_url',
        'thumbnail_url',
        'published_platform',
        'poll_attempts',
        'last_polled_at',
        'error',
        'publish_response',
    ];

    protected $casts = [
        'hashtags' => 'array',
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
