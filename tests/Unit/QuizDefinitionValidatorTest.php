<?php

namespace Tests\Unit;

use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuizDefinitionValidatorTest extends TestCase
{
    public function test_version_one_definition_requires_nonempty_blocks_and_labels(): void
    {
        $this->assertInvalid(['schema_version' => 1, 'blocks' => []]);
        $this->assertInvalid(['schema_version' => 1, 'blocks' => [['id' => 'q1', 'type' => 'question', 'question_type' => 'short_text', 'label' => '']]]);
        $this->assertInvalid(['schema_version' => 1, 'blocks' => [['id' => 'content', 'type' => 'content', 'markdown' => '']]]);
    }

    public function test_choice_options_and_identifiers_must_be_unique_and_complete(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'single_choice';
        $definition['blocks'][0]['options'] = [['id' => 'a', 'value' => 'same', 'label' => 'A'], ['id' => 'a', 'value' => 'same', 'label' => 'B']];
        $this->assertInvalid($definition);

        $definition['blocks'][0]['options'] = [];
        $this->assertInvalid($definition);
    }

    public function test_conditions_are_recursive_and_can_reference_only_earlier_questions_with_compatible_operands(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][] = ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Second', 'visibility' => ['all' => [
            ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'yes'],
            ['any' => [['question_id' => 'q1', 'operator' => 'not_empty']]],
        ]]];
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition['blocks'][1]['visibility']['all'][0]['question_id'] = 'q2';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'short_text';
        $definition['blocks'][] = ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Second', 'visibility' => ['question_id' => 'q1', 'operator' => 'greater_than', 'value' => 2]];
        $this->assertInvalid($definition);
    }

    public function test_condition_choice_operands_must_be_valid_and_page_breaks_cannot_be_leading_trailing_or_consecutive(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][] = ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Second', 'visibility' => ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'not-an-option']];
        $this->assertInvalid($definition);

        $this->assertInvalid(['schema_version' => 1, 'blocks' => [['id' => 'break', 'type' => 'page_break'], $this->baseDefinition()['blocks'][0]]]);
    }

    public function test_version_one_schema_rejects_unknown_keys_at_every_level(): void
    {
        $definition = $this->baseDefinition();
        $definition['unrecognized'] = true;
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['unrecognized'] = true;
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'single_choice';
        $definition['blocks'][0]['options'] = [['id' => 'yes', 'value' => 'yes', 'label' => 'Yes', 'unrecognized' => true]];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][] = ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Second', 'visibility' => ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'yes', 'unrecognized' => true]];
        $this->assertInvalid($definition);
    }

    public function test_version_one_schema_requires_boolean_required_and_bounded_type_appropriate_lengths(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][0]['required'] = 'true';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['max_length'] = 0;
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['max_length'] = 10001;
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'yes_no';
        $definition['blocks'][0]['max_length'] = 10;
        $this->assertInvalid($definition);

        $definition = ['schema_version' => 1, 'blocks' => [['id' => 'content', 'type' => 'content', 'markdown' => str_repeat('a', 10001), 'continue_label' => str_repeat('a', 201)]]];
        $this->assertInvalid($definition);
    }

    public function test_optional_opening_accepts_safe_fields_and_rejects_invalid_opening_payloads(): void
    {
        $definition = $this->baseDefinition();
        $definition['opening'] = [
            'html' => '<h1>Welcome</h1><p>Start when ready.</p>',
            'start_button_label' => 'Begin assessment',
            'hide_start_button' => false,
        ];
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition['opening'] = ['html' => '<p>Inline only</p>', 'hide_start_button' => true];
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition['opening'] = ['html' => '', 'start_button_label' => 'Start'];
        $this->assertInvalid($definition);

        $definition['opening'] = ['html' => '<p>Hi</p>', 'hide_start_button' => 'yes'];
        $this->assertInvalid($definition);

        $definition['opening'] = ['html' => '<p>Hi</p>', 'unrecognized' => true];
        $this->assertInvalid($definition);

        $definition['opening'] = ['html' => '<?php echo 1; ?>'];
        $this->assertInvalid($definition);

        $definition['opening'] = ['html' => '<p>Hi</p>', 'start_button_label' => str_repeat('a', 201)];
        $this->assertInvalid($definition);
    }

    public function test_optional_option_scores_and_score_result_bands_are_validated(): void
    {
        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score'];
        $definition['blocks'][0]['question_type'] = 'single_choice';
        $definition['blocks'][0]['options'] = [
            ['id' => 'a', 'value' => 'a', 'label' => 'A', 'score' => 2],
            ['id' => 'b', 'value' => 'b', 'label' => 'B', 'score' => 0],
        ];
        $definition['score_results'] = [
            ['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 1],
            ['id' => 'high', 'title' => 'High', 'min_score' => 2, 'max_score' => 10, 'html' => '<p>High</p>'],
        ];
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['yes_score'] = 1;
        $definition['blocks'][0]['no_score'] = 0;
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'single_choice';
        $definition['blocks'][0]['options'] = [['id' => 'a', 'value' => 'a', 'label' => 'A', 'score' => '2']];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['question_type'] = 'short_text';
        $definition['blocks'][0]['yes_score'] = 1;
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score'];
        $definition['score_results'] = [
            ['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 5],
            ['id' => 'overlap', 'title' => 'Overlap', 'min_score' => 5, 'max_score' => 10],
        ];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score'];
        $definition['score_results'] = [
            ['id' => 'bad', 'title' => 'Bad', 'min_score' => 5, 'max_score' => 1],
        ];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score'];
        $definition['score_results'] = [
            ['id' => 'dup', 'title' => 'One', 'min_score' => 0, 'max_score' => 1],
            ['id' => 'dup', 'title' => 'Two', 'min_score' => 2, 'max_score' => 3],
        ];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['score_results'] = [
            ['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 1],
        ];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'ai'];
        $definition['thank_you'] = ['enabled' => false];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score'];
        $definition['score_results'] = [
            ['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 10],
        ];
        $definition['thank_you'] = ['enabled' => false];
        app(QuizDefinitionValidator::class)->validate($definition);
    }

    public function test_optional_question_image_url_and_icon_are_validated(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][0]['image_url'] = 'https://cdn.example.test/q1.png';
        $definition['blocks'][0]['icon'] = '🚀';
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['image_url'] = 'javascript:alert(1)';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['image_url'] = 'ftp://example.test/q1.png';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['icon'] = '<script>x</script>';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['icon'] = str_repeat('a', 33);
        $this->assertInvalid($definition);
    }

    public function test_exclude_from_ai_and_ai_system_prompt_override_are_validated(): void
    {
        $definition = $this->baseDefinition();
        $definition['blocks'][0]['exclude_from_ai'] = true;
        $definition['result'] = [
            'mode' => 'ai',
            'system_prompt' => 'Use {{questions_and_answers}} and {{question.q1}} / {{answer.q1}}.',
        ];
        app(QuizDefinitionValidator::class)->validate($definition);

        $definition = $this->baseDefinition();
        $definition['blocks'][0]['exclude_from_ai'] = 'yes';
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'score', 'system_prompt' => 'Nope'];
        $definition['score_results'] = [['id' => 'low', 'title' => 'Low', 'min_score' => 0, 'max_score' => 1]];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'ai', 'system_prompt' => 'Bad {{answer.missing}}'];
        $this->assertInvalid($definition);

        $definition = $this->baseDefinition();
        $definition['result'] = ['mode' => 'ai', 'system_prompt' => 'Bad {{email}}'];
        $this->assertInvalid($definition);
    }

    private function baseDefinition(): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?', 'required' => true],
        ]];
    }

    private function assertInvalid(array $definition): void
    {
        try {
            app(QuizDefinitionValidator::class)->validate($definition);
            $this->fail('Definition should be invalid.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
