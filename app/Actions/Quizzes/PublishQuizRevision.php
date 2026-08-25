<?php

namespace App\Actions\Quizzes;

use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use App\Enums\QuizStatus;
use App\Models\Quiz;
use App\Models\QuizRevision;
use Illuminate\Support\Facades\DB;

class PublishQuizRevision
{
    public function __construct(private QuizDefinitionValidator $validator) {}

    public function handle(Quiz $quiz, ?int $userId = null): QuizRevision
    {
        return DB::transaction(function () use ($quiz, $userId) {
            $definition = $quiz->draft_definition ?? [];
            $this->validator->validate($definition);
            $version = ((int) $quiz->revisions()->max('version')) + 1;
            $revision = $quiz->revisions()->create(['version' => $version, 'definition' => $definition, 'published_by' => $userId, 'published_at' => now()]);
            $quiz->update(['active_revision_id' => $revision->id, 'status' => QuizStatus::Published]);

            return $revision;
        });
    }
}
