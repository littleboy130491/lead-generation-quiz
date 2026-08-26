<?php

namespace Tests\Unit;

use App\Domain\Quiz\Scoring\QuizScoreCalculator;
use Tests\TestCase;

class QuizScoreCalculatorTest extends TestCase
{
    public function test_it_sums_visible_choice_and_yes_no_scores_and_matches_a_result_band(): void
    {
        $definition = [
            'schema_version' => 1,
            'score_results' => [
                ['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 3, 'html' => '<p>Low band</p>'],
                ['id' => 'high', 'title' => 'High', 'min_score' => 4, 'max_score' => 20, 'html' => '<p>High band</p>'],
            ],
            'blocks' => [
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?', 'yes_score' => 2, 'no_score' => 0],
                ['id' => 'q2', 'type' => 'question', 'question_type' => 'single_choice', 'label' => 'Level', 'options' => [
                    ['id' => 'a', 'value' => 'a', 'label' => 'A', 'score' => 1],
                    ['id' => 'b', 'value' => 'b', 'label' => 'B', 'score' => 3],
                ]],
                ['id' => 'q3', 'type' => 'question', 'question_type' => 'multiple_choice', 'label' => 'Skills', 'options' => [
                    ['id' => 'x', 'value' => 'x', 'label' => 'X', 'score' => 2],
                    ['id' => 'y', 'value' => 'y', 'label' => 'Y', 'score' => 2],
                ]],
                ['id' => 'q4', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Notes'],
                ['id' => 'hidden', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Hidden', 'yes_score' => 100, 'visibility' => ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'no']],
            ],
        ];

        $result = app(QuizScoreCalculator::class)->calculate($definition, [
            'q1' => 'yes',
            'q2' => 'b',
            'q3' => ['x', 'y'],
            'q4' => 'ignored',
            'hidden' => 'yes',
        ]);

        $this->assertSame(9, $result['total']);
        $this->assertSame('high', $result['result']['id']);
        $this->assertSame('High', $result['result']['title']);
        $this->assertSame('<p>High band</p>', $result['result']['html']);
    }

    public function test_it_returns_null_when_the_definition_has_no_scoring(): void
    {
        $definition = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?'],
            ],
        ];

        $this->assertNull(app(QuizScoreCalculator::class)->calculate($definition, ['q1' => 'yes']));
    }
}
