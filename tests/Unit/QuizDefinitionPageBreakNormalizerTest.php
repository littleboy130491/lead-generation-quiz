<?php

namespace Tests\Unit;

use App\Ai\Data\QuizDefinitionPageBreakNormalizer;
use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuizDefinitionPageBreakNormalizerTest extends TestCase
{
    public function test_it_removes_only_leading_trailing_and_consecutive_page_breaks(): void
    {
        $definition = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'leading', 'type' => 'page_break'],
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'First question?'],
                ['id' => 'kept', 'type' => 'page_break'],
                ['id' => 'consecutive', 'type' => 'page_break'],
                ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Second question?'],
                ['id' => 'trailing', 'type' => 'page_break'],
            ],
        ];

        $normalized = QuizDefinitionPageBreakNormalizer::normalize($definition);

        $this->assertSame(['q1', 'kept', 'q2'], array_column($normalized['blocks'], 'id'));
        app(QuizDefinitionValidator::class)->validate($normalized);
    }

    public function test_it_does_not_hide_other_invalid_blocks(): void
    {
        $definition = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'leading', 'type' => 'page_break'],
                ['id' => 'invalid', 'type' => 'question', 'question_type' => 'unsupported', 'label' => 'Invalid question'],
            ],
        ];

        $normalized = QuizDefinitionPageBreakNormalizer::normalize($definition);

        $this->assertSame('invalid', $normalized['blocks'][0]['id']);
        $this->expectException(ValidationException::class);
        app(QuizDefinitionValidator::class)->validate($normalized);
    }
}
