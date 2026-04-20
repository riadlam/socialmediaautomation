<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Script extends Model
{
    protected $fillable = [
        'script',
        'status',
        'video_id',
        'video_url',
        'poll_attempts',
        'error',
        'publish_response',
    ];

    protected $casts = [
        'publish_response' => 'array',
    ];
}

