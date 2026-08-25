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
