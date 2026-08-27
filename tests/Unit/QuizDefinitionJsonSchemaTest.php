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
