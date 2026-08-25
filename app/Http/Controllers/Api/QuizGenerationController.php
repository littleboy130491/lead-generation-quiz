<?php

namespace App\Http\Controllers\Api;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Ai\GenerationException;
use App\Enums\QuizStatus;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizGenerationController extends Controller
{
    public function __invoke(Request $request, GenerateQuizDraft $generateDraft, PublishQuizRevision $publish): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:quizzes,slug'],
            'description' => ['nullable', 'string', 'max:2000'],
            'publish' => ['sometimes', 'boolean'],
            'brief' => ['required', 'array'],
            'brief.business_context' => ['required', 'string', 'max:4000'],
            'brief.target_audience' => ['nullable', 'string', 'max:500'],
            'brief.objective' => ['nullable', 'string', 'max:500'],
            'brief.desired_insight' => ['nullable', 'string', 'max:500'],
            'brief.question_count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'brief.tone' => ['nullable', 'string', 'max:100'],
        ]);

        $quiz = Quiz::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => QuizStatus::Draft,
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
            'settings' => [],
        ]);

        try {
            $quiz = $generateDraft->handle($quiz, $data['brief']);
            $revision = null;
            if ($data['publish'] ?? false) {
                $revision = $publish->handle($quiz);
                $quiz->refresh();
            }
        } catch (GenerationException $exception) {
            return response()->json(['error' => [
                'code' => $exception->codeName,
                'message' => 'Quiz generation is currently unavailable.',
                'quiz_id' => $quiz->id,
            ], ], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['error' => [
                'code' => 'quiz_generation_failed',
                'message' => 'Quiz generation failed.',
                'quiz_id' => $quiz->id,
            ], ], 502);
        }

        return response()->json([
            'data' => [
                'id' => $quiz->id,
                'name' => $quiz->name,
                'slug' => $quiz->slug,
                'status' => $quiz->status->value,
                'definition' => $quiz->draft_definition,
                'revision' => $revision ? ['id' => $revision->id, 'version' => $revision->version] : null,
                'public_url' => $revision ? route('quizzes.show', $quiz) : null,
                'created_at' => $quiz->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
