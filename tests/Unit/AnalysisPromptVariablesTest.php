<?php

namespace Tests\Unit;

use App\Ai\Prompt\AnalysisPromptBuilder;
use App\Ai\Prompt\AnalysisPromptVariables;
use App\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AnalysisPromptVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_excluded_questions_are_omitted_from_context_and_aggregate_variable(): void
    {
        $definition = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?'],
                ['id' => 'secret', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Internal note', 'exclude_from_ai' => true],
            ],
        ];
        $answers = ['q1' => 'yes', 'secret' => 'do not send'];
        $variables = app(AnalysisPromptVariables::class);

        $contextRevision = $variables->filterRevision($definition);
        $contextAnswers = $variables->filterAnswers($definition, $answers);

        $this->assertSame(['q1'], array_column($contextRevision['blocks'], 'id'));
        $this->assertSame(['q1' => 'yes'], $contextAnswers);
        $this->assertStringContainsString('Ready?', $variables->formatQuestionsAndAnswers($definition, $answers));
        $this->assertStringNotContainsString('Internal note', $variables->formatQuestionsAndAnswers($definition, $answers));
        $this->assertStringNotContainsString('do not send', $variables->formatQuestionsAndAnswers($definition, $answers));
    }

    public function test_global_template_substitutes_aggregate_and_quiz_override_supports_per_id(): void
    {
        app(ApplicationSettings::class)->put('prompts', [
            'quiz_version' => 'v1',
            'quiz_template' => '',
            'report_version' => 'v1',
            'report_template' => 'Global uses {{questions_and_answers}} only.',
        ]);

        $definition = [
            'schema_version' => 1,
            'result' => [
                'mode' => 'ai',
                'system_prompt' => 'Focus on {{question.q1}} answered {{answer.q1}}. All: {{questions_and_answers}}',
            ],
            'blocks' => [
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?'],
                ['id' => 'secret', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Secret', 'exclude_from_ai' => true],
            ],
        ];
        $answers = ['q1' => 'yes', 'secret' => 'hidden'];

        $override = app(AnalysisPromptBuilder::class)->build($definition, $answers);
        $this->assertStringContainsString('Focus on', $override->system);
        $this->assertStringContainsString('Ready?', $override->system);
        $this->assertStringContainsString('Yes', $override->system);
        $this->assertStringNotContainsString('hidden', $override->system);
        $this->assertStringNotContainsString('"secret"', $override->user);

        unset($definition['result']['system_prompt']);
        $global = app(AnalysisPromptBuilder::class)->build($definition, $answers);
        $this->assertStringContainsString('Global uses', $global->system);
        $this->assertStringContainsString('questions_and_answers', $global->system);
        $this->assertStringContainsString('Ready?', $global->system);
    }

    public function test_global_settings_reject_per_question_placeholders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ApplicationSettings::class)->put('prompts', [
            'quiz_version' => 'v1',
            'quiz_template' => '',
            'report_version' => 'v1',
            'report_template' => 'Bad {{answer.q1}}',
        ]);
    }
}
