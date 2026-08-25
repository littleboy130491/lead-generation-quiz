<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizRevision extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->isDirty(['quiz_id', 'version', 'definition', 'report_prompt_snapshot', 'published_by', 'published_at'])) {
                throw new \LogicException('Published quiz revisions are immutable.');
            }
        });

        static::deleting(function (): void {
            throw new \LogicException('Published quiz revisions cannot be deleted.');
        });
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
