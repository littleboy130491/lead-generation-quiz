<?php

namespace App\Actions\Quizzes;

use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\GenerationException;
use App\Ai\Prompt\QuizDefinitionPrompt;
use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use App\Models\Quiz;
use App\Models\QuizDraftGeneration;
use App\Settings\ApplicationSettings;
use App\Support\RequestTimeLimit;
use Closure;
use Illuminate\Support\Facades\DB;

class GenerateQuizDraft
{
    public function __construct(
        private QuizDefinitionGenerator $generator,
        private QuizDefinitionValidator $validator,
        private ApplicationSettings $settings,
        private QuizDefinitionPrompt $prompt,
    ) {}

    public function handle(
        Quiz $quiz,
        array $brief,
        ?Closure $beforePersist = null,
        ?Closure $afterPersist = null,
        ?array $sourceQuizSnapshot = null,
    ): Quiz {
        $brief = $this->safeBrief($brief);
        if ($sourceQuizSnapshot !== null) {
            $brief['existing_quiz'] = $this->safeSourceQuizSnapshot($sourceQuizSnapshot);
        }
        $prompts = $this->settings->get('prompts');
        $chain = $this->settings->get('ai.quiz');
        $systemPrompt = $this->prompt->compose((string) $prompts['quiz_template']);
        RequestTimeLimit::extendForAiCall($this->settings->operation('timeout_seconds'), count($chain));
        $audit = QuizDraftGeneration::create([
            'quiz_id' => $quiz->id,
            'brief_hash' => hash('sha256', json_encode($brief, JSON_THROW_ON_ERROR)),
            'requested_provider_chain' => $chain,
            'prompt_version' => $prompts['quiz_version'],
            'system_prompt_snapshot' => $systemPrompt,
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        try {
            $definition = $this->generator->generate($brief, $chain, $systemPrompt);
            $this->validator->validate($definition);

            DB::transaction(function () use ($quiz, $definition, $audit, $beforePersist, $afterPersist): void {
                if ($beforePersist !== null && ! $beforePersist()) {
                    $audit->update([
                        'status' => 'cancelled',
                        'error_code' => 'cancelled_by_admin',
                        'error_message' => 'Stopped by the administrator before draft persistence.',
                        'cancelled_at' => now(),
                    ]);

                    return;
                }

                Quiz::query()->findOrFail($quiz->id)->update(['draft_definition' => $definition]);
                $audit->update([
                    'status' => 'completed',
                    'result_hash' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR)),
                    'completed_at' => now(),
                ]);

                if ($afterPersist !== null) {
                    $afterPersist();
                }
            });
        } catch (\Throwable $exception) {
            $audit->update([
                'status' => 'failed',
                'error_code' => $exception instanceof GenerationException ? $exception->codeName : 'quiz_generation_failed',
                'error_message' => str($exception->getMessage())->limit(500)->toString(),
                'failed_at' => now(),
            ]);

            throw $exception;
        }

        return $quiz->fresh();
    }

    /** @return array<string, mixed> */
    private function safeBrief(array $brief): array
    {
        return array_filter([
            'business_context' => $brief['business_context'] ?? null,
            'target_audience' => $brief['target_audience'] ?? null,
            'objective' => $brief['objective'] ?? null,
            'desired_insight' => $brief['desired_insight'] ?? null,
            'question_count' => isset($brief['question_count']) ? (int) $brief['question_count'] : null,
            'tone' => $brief['tone'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{name: string, description: ?string, draft_definition: array<string, mixed>}
     */
    private function safeSourceQuizSnapshot(array $snapshot): array
    {
        $definition = $snapshot['draft_definition'] ?? null;
        if (! is_array($definition)) {
            throw new \InvalidArgumentException('The existing quiz context must contain a draft definition.');
        }

        return [
            'name' => mb_substr(trim(strip_tags((string) ($snapshot['name'] ?? ''))), 0, 255),
            'description' => filled($snapshot['description'] ?? null)
                ? mb_substr(trim(strip_tags((string) $snapshot['description'])), 0, 4000)
                : null,
            'draft_definition' => $definition,
        ];
    }
}
