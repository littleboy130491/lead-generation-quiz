<?php

namespace App\Models;

use App\Enums\QuizStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $quiz): void {
            if (in_array(strtolower($quiz->slug), config('quiz.reserved_slugs', []), true)) {
                throw ValidationException::withMessages(['slug' => 'This slug is reserved.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => QuizStatus::class, 'draft_definition' => 'array', 'settings' => 'array'];
    }

    public function revisions()
    {
        return $this->hasMany(QuizRevision::class);
    }

    public function draftGenerations()
    {
        return $this->hasMany(QuizDraftGeneration::class);
    }

    public function activeRevision()
    {
        return $this->belongsTo(QuizRevision::class, 'active_revision_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
