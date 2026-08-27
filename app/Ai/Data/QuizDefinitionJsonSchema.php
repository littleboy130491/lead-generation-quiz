<?php

namespace App\Ai\Data;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Structured-output schema for V1 quiz drafts. Providers use this to constrain
 * generation; QuizDefinitionValidator remains the authority after the call.
 */
final class QuizDefinitionJsonSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(JsonSchema $schema): array
    {
        $option = $schema->object([
            'id' => $schema->string()->required(),
            'value' => $schema->string()->required(),
            'label' => $schema->string()->required(),
            'score' => $schema->integer(),
        ]);

        $visibility = $schema->object([
            'question_id' => $schema->string()->required(),
            'operator' => $schema->string()->required(),
            'value' => $schema->string(),
        ]);

        $block = $schema->object([
            'id' => $schema->string()->required(),
            'type' => $schema->string()->required(),
            'question_type' => $schema->string(),
            'label' => $schema->string(),
            'help' => $schema->string(),
            'required' => $schema->boolean(),
            'max_length' => $schema->integer(),
            'options' => $schema->array()->items($option),
            'visibility' => $visibility,
            'yes_score' => $schema->integer(),
            'no_score' => $schema->integer(),
            'image_url' => $schema->string(),
            'icon' => $schema->string(),
            'exclude_from_ai' => $schema->boolean(),
            'markdown' => $schema->string(),
            'continue_label' => $schema->string(),
        ]);

        return [
            'schema_version' => $schema->integer()->required(),
            'opening' => $schema->object([
                'html' => $schema->string()->required(),
                'start_button_label' => $schema->string(),
                'hide_start_button' => $schema->boolean(),
            ]),
            'result' => $schema->object([
                'mode' => $schema->string()->required(),
                'system_prompt' => $schema->string(),
            ])->required(),
            'score_results' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'min_score' => $schema->integer()->required(),
                'max_score' => $schema->integer()->required(),
                'html' => $schema->string(),
            ])),
            'thank_you' => $schema->object([
                'enabled' => $schema->boolean()->required(),
                'html' => $schema->string(),
            ]),
            'blocks' => $schema->array()->items($block)->required(),
        ];
    }
}
