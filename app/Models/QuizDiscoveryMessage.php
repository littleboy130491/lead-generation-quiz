<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuizDiscoveryMessage extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quiz discovery messages are append-only.'));
        static::deleting(fn () => throw new LogicException('Quiz discovery messages are append-only.'));
    }

    protected function casts(): array
    {
        return ['brief_snapshot' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(QuizDiscoverySession::class, 'quiz_discovery_session_id');
    }
}
