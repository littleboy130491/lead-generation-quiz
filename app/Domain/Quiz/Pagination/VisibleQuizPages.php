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
        return array_values(array_filter(array_map(function (array $page) use ($answers): array {
            return array_values(array_filter($page, fn (array $block): bool => $this->conditions->visible(
                $block['visibility'] ?? null,
                $answers,
            )));
        }, $this->compiler->compile($submission->quizRevision->definition)), fn (array $page): bool => $page !== []));
    }
}
