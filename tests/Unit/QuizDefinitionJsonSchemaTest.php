<?php

namespace Tests\Unit;

use App\Ai\Data\QuizDefinitionJsonSchema;
use App\Ai\Data\QuizDefinitionSanitizer;
use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Tests\TestCase;

class QuizDefinitionJsonSchemaTest extends TestCase
{
    public function test_every_property_is_required_so_strict_structured_output_is_accepted(): void
    {
        $missing = [];
        $this->walk($this->schema(), 'root', $missing);

        $this->assertSame([], $missing);
    }

    public function test_optional_fields_are_nullable_rather_than_omitted(): void
    {
        $schema = $this->schema();
        $block = $schema['properties']['blocks']['items'];

        $this->assertSame('string', $block['properties']['id']['type']);
        $this->assertSame(['string', 'null'], $block['properties']['markdown']['type']);
        $this->assertSame(['array', 'null'], $block['properties']['options']['type']);
        $this->assertSame(['object', 'null'], $block['properties']['visibility']['type']);
        $this->assertSame(QuizDefinitionJsonSchema::BLOCK_TYPES, $block['properties']['type']['enum']);
    }

    public function test_fields_the_model_may_not_invent_are_absent_from_the_schema(): void
    {
        $block = $this->schema()['properties']['blocks']['items'];

        $this->assertArrayNotHasKey('image_url', $block['properties']);
        $this->assertArrayNotHasKey('icon', $block['properties']);
    }

    public function test_sanitizer_drops_strict_mode_null_placeholders(): void
    {
        $definition = QuizDefinitionSanitizer::sanitize([
            'schema_version' => 1,
            'opening' => null,
            'result' => ['mode' => 'ai', 'system_prompt' => null],
            'score_results' => null,
            'thank_you' => ['enabled' => true, 'html' => null],
            'blocks' => [
                [
                    'id' => 'q1',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'How large is your team?',
                    'help' => null,
                    'required' => true,
                    'max_length' => null,
                    'options' => [
                        ['id' => 'o1', 'value' => 'small', 'label' => 'Under ten', 'score' => null],
                        ['id' => 'o2', 'value' => 'large', 'label' => 'Ten or more', 'score' => null],
                    ],
                    'visibility' => null,
                    'yes_score' => null,
                    'no_score' => null,
                    'image_url' => null,
                    'icon' => null,
                    'exclude_from_ai' => null,
                    'markdown' => null,
                    'continue_label' => null,
                ],
                [
                    'id' => 'c1',
                    'type' => 'content',
                    'question_type' => null,
                    'label' => null,
                    'help' => null,
                    'required' => null,
                    'max_length' => null,
                    'options' => null,
                    'visibility' => null,
                    'yes_score' => null,
                    'no_score' => null,
                    'image_url' => null,
                    'icon' => null,
                    'exclude_from_ai' => null,
                    'markdown' => 'Thanks for the detail.',
                    'continue_label' => null,
                ],
            ],
        ]);

        app(QuizDefinitionValidator::class)->validate($definition);

        $this->assertArrayNotHasKey('opening', $definition);
        $this->assertArrayNotHasKey('score_results', $definition);
        $this->assertSame(['id', 'type', 'markdown'], array_keys($definition['blocks'][1]));
        $this->assertSame(['id', 'value', 'label'], array_keys($definition['blocks'][0]['options'][0]));
    }

    public function test_sanitizer_drops_falsy_placeholders_that_do_not_belong_to_the_block_type(): void
    {
        $definition = QuizDefinitionSanitizer::sanitize([
            'schema_version' => 1,
            'result' => ['mode' => 'ai', 'system_prompt' => null],
            'blocks' => [
                [
                    'id' => 'q1',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'How large is your team?',
                    'help' => '',
                    'required' => true,
                    'max_length' => 0,
                    'options' => [['id' => 'o1', 'value' => 'small', 'label' => 'Under ten', 'score' => 0]],
                    'yes_score' => 0,
                    'no_score' => 0,
                    'exclude_from_ai' => false,
                    'markdown' => '',
                    'continue_label' => '',
                ],
                [
                    'id' => 'p1',
                    'type' => 'page_break',
                    'question_type' => null,
                    'label' => '',
                    'required' => false,
                    'yes_score' => 0,
                    'exclude_from_ai' => false,
                    'options' => [],
                ],
                [
                    'id' => 'q2',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Do you track cycle time?',
                    'required' => true,
                    'options' => [],
                    'yes_score' => 0,
                    'no_score' => 0,
                    'max_length' => 200,
                ],
            ],
        ]);

        app(QuizDefinitionValidator::class)->validate($definition);

        $this->assertSame(['id', 'type'], array_keys($definition['blocks'][1]));
        $this->assertSame(['id', 'type', 'question_type', 'label', 'required', 'options', 'exclude_from_ai'], array_keys($definition['blocks'][0]));
        $this->assertSame(['id', 'type', 'question_type', 'label', 'required', 'yes_score', 'no_score'], array_keys($definition['blocks'][2]));
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return (new ObjectSchema(QuizDefinitionJsonSchema::definition(new JsonSchemaTypeFactory)))->toSchema();
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $missing
     */
    private function walk(array $node, string $path, array &$missing): void
    {
        $types = is_array($node['type'] ?? null) ? $node['type'] : [$node['type'] ?? null];

        if (in_array('object', $types, true) && isset($node['properties'])) {
            foreach (array_diff(array_keys($node['properties']), $node['required'] ?? []) as $property) {
                $missing[] = $path.'/'.$property;
            }
            foreach ($node['properties'] as $key => $property) {
                $this->walk($property, $path.'/'.$key, $missing);
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            $this->walk($node['items'], $path.'[]', $missing);
        }
    }
}
