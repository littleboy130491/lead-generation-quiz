<?php

namespace App\Domain\Quiz\Pagination;

use App\Domain\Quiz\Conditions\ConditionEvaluator;
use App\Models\Submission;

class VisibleQuizPages
{
    public function __construct(
        private QuizPageCompiler $compiler,
        private ConditionEvaluator $conditions,
    ) {}

    /** @return array<int, array<int, array<string, mixed>>> */
    public function forSubmission(Submission $submission, array $answers): array
    {
        return $this->forDefinition($submission->quizRevision->definition ?? [], $answers);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forDefinition(array $definition, array $answers): array
    {
        $blocks = $definition['blocks'] ?? [];
        if (! is_array($blocks) || $blocks === []) {
            return [];
        }

        return array_values(array_filter(array_map(function (array $page) use ($answers): array {
            return array_values(array_filter($page, fn (array $block): bool => $this->conditions->visible(
                $block['visibility'] ?? null,
                $answers,
            )));
        }, $this->compiler->compile($definition)), fn (array $page): bool => $page !== []));
    }
}
