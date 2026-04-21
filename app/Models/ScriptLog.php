<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptLog extends Model
{
    protected $fillable = [
        'script_id',
        'stage',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }
}
