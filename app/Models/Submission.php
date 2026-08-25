<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Submission extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $submission): void {
            $dirty = array_keys($submission->getDirty());
            if ($submission->getOriginal('started_at') !== null && array_intersect($dirty, ['public_id', 'quiz_id', 'quiz_revision_id', 'quiz_snapshot', 'first_touch_context'])) {
                throw new LogicException('Started submission identity and initial snapshots are immutable.');
            }
            if (($submission->getOriginal('questionnaire_completed_at') !== null || $submission->getOriginal('completed_at') !== null) && in_array('answers_snapshot', $dirty, true)) {
                throw new LogicException('Completed questionnaire answer snapshots are immutable.');
            }
        });

        static::deleting(function (self $submission): void {
            if ($submission->completed_at !== null) {
                throw new LogicException('Completed submissions retain protected historical records.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'answers_snapshot' => 'array', 'quiz_snapshot' => 'array', 'metadata' => 'array', 'first_touch_context' => 'array', 'latest_touch_context' => 'array', 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'questionnaire_completed_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function quizRevision()
    {
        return $this->belongsTo(QuizRevision::class);
    }

    public function analyses()
    {
        return $this->hasMany(Analysis::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ReportDelivery::class);
    }

    public function events()
    {
        return $this->hasMany(SubmissionEvent::class)->orderBy('created_at');
    }
}
