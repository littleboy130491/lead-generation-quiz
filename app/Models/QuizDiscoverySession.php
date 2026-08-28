<?php

namespace App\Models;

use App\Enums\QuizDiscoveryMode;
use App\Enums\QuizDiscoveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class QuizDiscoverySession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'mode' => QuizDiscoveryMode::class,
            'source_quiz_snapshot' => 'array',
            'status' => QuizDiscoveryStatus::class,
            'generation_started_at' => 'datetime',
            'generation_finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            if ($session->isDirty('source_quiz_snapshot')) {
                throw new LogicException('The source quiz snapshot for an AI edit interview is immutable.');
            }
            if ($session->isDirty('continued_from_session_id')) {
                throw new LogicException('The parent AI edit session is immutable.');
            }
        });
    }

    public function continuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'continued_from_session_id');
    }

    public function continuation(): HasOne
    {
        return $this->hasOne(self::class, 'continued_from_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(QuizDiscoveryMessage::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
