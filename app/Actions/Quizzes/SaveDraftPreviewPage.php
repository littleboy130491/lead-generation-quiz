<?php

namespace App\Actions\Quizzes;

use App\Domain\Quiz\Opening\QuizOpening;
use App\Domain\Quiz\Pagination\VisibleQuizPages;
use App\Models\Quiz;
use App\Services\QuizDraftPreview;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaveDraftPreviewPage
{
    public function __construct(
        private VisibleQuizPages $pages,
        private QuizDraftPreview $preview,
    ) {}

    public function handle(Quiz $quiz, int $page, array $providedAnswers, string $direction, Request $request): string
    {
        $definition = $this->preview->definition($quiz);
        $state = $this->preview->state($request, $quiz);

        if (QuizOpening::isPending($definition, $state)) {
            throw ValidationException::withMessages(['opening' => 'Complete the opening page before answering questions.']);
        }

        $answers = $state->answers_snapshot;
        $visiblePages = $this->pages->forDefinition($definition, $answers);
        if ($visiblePages === []) {
            return 'finished';
        }
        if ($page !== $state->current_page || ! isset($visiblePages[$page])) {
            throw ValidationException::withMessages(['page' => 'That questionnaire page is no longer current.']);
        }

        $allowedAnswerIds = collect($visiblePages[$page])
            ->filter(fn (array $block): bool => ($block['type'] ?? null) === 'question')
            ->map(fn (array $block): string => (string) $block['id'])
            ->all();
        $unknownAnswerIds = array_values(array_filter(
            array_keys($providedAnswers),
            fn (int|string $id): bool => ! in_array((string) $id, $allowedAnswerIds, true),
        ));
        if ($unknownAnswerIds !== []) {
            throw ValidationException::withMessages(
                array_fill_keys(
                    array_map(fn (int|string $id): string => 'answers.'.$id, $unknownAnswerIds),
                    'This answer does not belong to the current questionnaire page.',
                ),
            );
        }

        $errors = [];
        foreach ($visiblePages[$page] as $block) {
            if (($block['type'] ?? null) !== 'question') {
                continue;
            }

            $id = $block['id'];
            $answer = $providedAnswers[$id] ?? (($block['question_type'] ?? null) === 'multiple_choice' ? [] : null);
            try {
                $answers[$id] = $this->validatedAnswer($block, $answer, $direction === 'next');
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $state->answers_snapshot = $answers;

        if ($direction === 'back') {
            if ($page === 0 && QuizOpening::isGated($definition)) {
                $state->metadata['opening_dismissed'] = false;
                $state->current_page = 0;
                $this->preview->put($request, $quiz, $state);

                return 'show';
            }

            $state->current_page = max(0, $page - 1);
            $this->preview->put($request, $quiz, $state);

            return 'show';
        }

        $visiblePages = $this->pages->forDefinition($definition, $answers);
        if (isset($visiblePages[$page + 1])) {
            $state->current_page = $page + 1;
            $this->preview->put($request, $quiz, $state);

            return 'show';
        }

        $state->current_page = $page;
        $this->preview->put($request, $quiz, $state);

        return 'finished';
    }

    /** @param  array<string, mixed>  $block */
    private function validatedAnswer(array $block, mixed $answer, bool $required): mixed
    {
        $key = 'answers.'.$block['id'];
        $type = $block['question_type'] ?? '';
        $empty = $answer === null || $answer === '' || $answer === [];
        if ($required && ($block['required'] ?? false) && $empty) {
            throw ValidationException::withMessages([$key => 'This answer is required.']);
        }
        if ($empty) {
            return $type === 'multiple_choice' ? [] : null;
        }

        $options = collect($block['options'] ?? [])->map(fn (array $option) => (string) ($option['value'] ?? $option['id'] ?? ''))->all();
        if ($type === 'yes_no') {
            if (! is_string($answer) || ! in_array($answer, ['yes', 'no'], true)) {
                throw ValidationException::withMessages([$key => 'Choose yes or no.']);
            }
        } elseif ($type === 'single_choice') {
            if (! is_string($answer) || ! in_array($answer, $options, true)) {
                throw ValidationException::withMessages([$key => 'Choose one of the available options.']);
            }
        } elseif ($type === 'multiple_choice') {
            if (! is_array($answer) || array_filter($answer, fn ($value) => ! is_string($value) || ! in_array($value, $options, true)) !== []) {
                throw ValidationException::withMessages([$key => 'Choose only available options.']);
            }

            return array_values(array_unique($answer));
        } elseif (in_array($type, ['short_text', 'long_text'], true)) {
            $max = (int) ($block['max_length'] ?? ($type === 'short_text' ? 255 : 5000));
            if (! is_string($answer) || mb_strlen($answer) > $max) {
                throw ValidationException::withMessages([$key => "Enter text no longer than {$max} characters."]);
            }
        } else {
            throw ValidationException::withMessages([$key => 'Unsupported question type.']);
        }

        return $answer;
    }
}
