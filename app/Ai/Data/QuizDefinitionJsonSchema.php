<?php

namespace App\Ai\Data;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Structured-output schema for V1 quiz drafts. Providers use this to constrain
 * generation; QuizDefinitionValidator remains the authority after the call.
 *
 * Providers apply this schema in strict mode, which requires every property to
 * be listed as required. Fields that the definition treats as optional are
 * therefore required-and-nullable, and QuizDefinitionSanitizer drops the nulls
 * before validation.
 */
final class QuizDefinitionJsonSchema
{
    public const BLOCK_TYPES = ['question', 'content', 'page_break'];

    public const QUESTION_TYPES = ['single_choice', 'multiple_choice', 'yes_no', 'short_text', 'long_text'];

    public const RESULT_MODES = ['ai', 'score'];

    public const VISIBILITY_OPERATORS = ['equals', 'not_equals', 'contains'];

    /**
     * @return array<string, mixed>
     */
    public static function definition(JsonSchema $schema): array
    {
        $option = $schema->object([
            'id' => $schema->string()->required(),
            'value' => $schema->string()->required(),
            'label' => $schema->string()->required(),
            'score' => $schema->integer()->nullable()->required(),
        ]);

        $visibility = $schema->object([
            'question_id' => $schema->string()->required(),
            'operator' => $schema->string()->enum(self::VISIBILITY_OPERATORS)->required(),
            'value' => $schema->string()->nullable()->required(),
        ])->nullable()->required();

        $block = $schema->object([
            'id' => $schema->string()->required(),
            'type' => $schema->string()->enum(self::BLOCK_TYPES)->required(),
            'question_type' => $schema->string()->enum(self::QUESTION_TYPES)->nullable()->required(),
            'label' => $schema->string()->nullable()->required(),
            'help' => $schema->string()->nullable()->required(),
            'required' => $schema->boolean()->nullable()->required(),
            'max_length' => $schema->integer()->nullable()->required(),
            'options' => $schema->array()->items($option)->nullable()->required(),
            'visibility' => $visibility,
            'yes_score' => $schema->integer()->nullable()->required(),
            'no_score' => $schema->integer()->nullable()->required(),
            'image_url' => $schema->string()->nullable()->required(),
            'icon' => $schema->string()->nullable()->required(),
            'exclude_from_ai' => $schema->boolean()->nullable()->required(),
            'markdown' => $schema->string()->nullable()->required(),
            'continue_label' => $schema->string()->nullable()->required(),
        ]);

        return [
            'schema_version' => $schema->integer()->required(),
            'opening' => $schema->object([
                'html' => $schema->string()->required(),
                'start_button_label' => $schema->string()->nullable()->required(),
                'hide_start_button' => $schema->boolean()->nullable()->required(),
            ])->nullable()->required(),
            'result' => $schema->object([
                'mode' => $schema->string()->enum(self::RESULT_MODES)->required(),
                'system_prompt' => $schema->string()->nullable()->required(),
            ])->required(),
            'score_results' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'min_score' => $schema->integer()->required(),
                'max_score' => $schema->integer()->required(),
                'html' => $schema->string()->nullable()->required(),
            ]))->nullable()->required(),
            'thank_you' => $schema->object([
                'enabled' => $schema->boolean()->required(),
                'html' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),
            'blocks' => $schema->array()->items($block)->required(),
        ];
    }
}
