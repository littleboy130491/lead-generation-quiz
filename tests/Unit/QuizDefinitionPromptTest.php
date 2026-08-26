<?php

namespace Tests\Unit;

use App\Ai\Prompt\QuizDefinitionPrompt;
use Tests\TestCase;

class QuizDefinitionPromptTest extends TestCase
{
    public function test_it_composes_the_administrator_instruction_with_the_required_quiz_definition_output_contract(): void
    {
        $prompt = app(QuizDefinitionPrompt::class)->compose('Write an approachable business-readiness quiz.');

        $this->assertStringContainsString('Write an approachable business-readiness quiz.', $prompt);
        $this->assertStringContainsString('Return exactly one JSON object and nothing else.', $prompt);
        $this->assertStringContainsString('"schema_version": 1', $prompt);
        $this->assertStringContainsString('"blocks": [', $prompt);
        $this->assertStringContainsString('single_choice', $prompt);
        $this->assertStringContainsString('page_break', $prompt);
        $this->assertStringContainsString('Do not include Markdown fences', $prompt);
    }
}
