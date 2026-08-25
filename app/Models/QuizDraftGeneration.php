<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class QuizDraftGeneration extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $generation): void {
            $requestFields = ['quiz_id', 'brief_hash', 'requested_provider_chain', 'prompt_version', 'system_prompt_snapshot'];

            if (array_intersect(array_keys($generation->getDirty()), $requestFields)) {
                throw new LogicException('Quiz draft generation request snapshots are immutable.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Quiz draft generation audit records are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'requested_provider_chain' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
