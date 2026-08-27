<?php

namespace App\Models;

use App\Enums\QuizDiscoveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizDiscoverySession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'status' => QuizDiscoveryStatus::class,
            'generation_started_at' => 'datetime',
            'generation_finished_at' => 'datetime',
        ];
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
