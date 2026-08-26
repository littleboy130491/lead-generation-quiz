<?php

namespace App\Services;

use App\Domain\Quiz\DraftPreviewState;
use App\Models\Quiz;
use Illuminate\Http\Request;

class QuizDraftPreview
{
    public function canAccess(Request $request, Quiz $quiz): bool
    {
        if ($quiz->status->value !== 'draft') {
            return false;
        }

        $user = $request->user();

        return $user !== null && method_exists($user, 'canAccessPanel');
    }

    public function state(Request $request, Quiz $quiz): DraftPreviewState
    {
        $payload = $request->session()->get($this->key($quiz), []);

        return new DraftPreviewState(
            current_page: (int) ($payload['current_page'] ?? 0),
            answers_snapshot: is_array($payload['answers'] ?? null) ? $payload['answers'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    public function put(Request $request, Quiz $quiz, DraftPreviewState $state): void
    {
        $request->session()->put($this->key($quiz), [
            'current_page' => $state->current_page,
            'answers' => $state->answers_snapshot,
            'metadata' => $state->metadata,
        ]);
    }

    public function definition(Quiz $quiz): array
    {
        $definition = $quiz->draft_definition;

        return is_array($definition) ? $definition : ['schema_version' => 1, 'blocks' => []];
    }

    private function key(Quiz $quiz): string
    {
        return 'quiz_draft_preview.'.$quiz->id;
    }
}
