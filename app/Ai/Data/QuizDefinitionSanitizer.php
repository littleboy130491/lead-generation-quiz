<?php

namespace App\Ai\Data;

/**
 * Strict structured output forces providers to emit every schema property, so
 * unused optional fields arrive as null, empty, or as a falsy placeholder such
 * as false or 0. The persisted definition contract accepts only the fields that
 * belong to a given block and question type, so the placeholders are removed
 * before validation rather than reported as a contract violation.
 */
final class QuizDefinitionSanitizer
{
    private const DEFINITION_KEYS = ['schema_version', 'opening', 'result', 'score_results', 'thank_you', 'blocks'];

    private const OPENING_KEYS = ['html', 'start_button_label', 'hide_start_button'];

    private const RESULT_KEYS = ['mode', 'system_prompt'];

    private const SCORE_RESULT_KEYS = ['id', 'title', 'min_score', 'max_score', 'html'];

    private const THANK_YOU_KEYS = ['enabled', 'html'];

    private const OPTION_KEYS = ['id', 'value', 'label', 'score'];

    private const VISIBILITY_KEYS = ['question_id', 'operator', 'value'];

    private const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    private const TEXT_TYPES = ['short_text', 'long_text'];

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function sanitize(array $definition): array
    {
        /** @var array<string, mixed> $pruned */
        $pruned = self::prune($definition);
        $definition = self::only($pruned, self::DEFINITION_KEYS);

        foreach ([['opening', self::OPENING_KEYS], ['result', self::RESULT_KEYS], ['thank_you', self::THANK_YOU_KEYS]] as [$key, $allowed]) {
            if (is_array($definition[$key] ?? null)) {
                $definition[$key] = self::only($definition[$key], $allowed);
            }
        }

        if (is_array($definition['score_results'] ?? null)) {
            $definition['score_results'] = array_values(array_map(
                fn (mixed $band): mixed => is_array($band) ? self::only($band, self::SCORE_RESULT_KEYS) : $band,
                $definition['score_results'],
            ));
        }

        if (is_array($definition['blocks'] ?? null)) {
            $definition['blocks'] = array_values(array_map(
                fn (mixed $block): mixed => is_array($block) ? self::block($block) : $block,
                $definition['blocks'],
            ));
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function block(array $block): array
    {
        $block = self::only($block, match ($block['type'] ?? null) {
            'content' => ['id', 'type', 'markdown', 'continue_label', 'visibility'],
            'page_break' => ['id', 'type'],
            default => ['id', 'type', 'question_type', 'label', 'help', 'required', 'max_length', 'options', 'visibility', 'yes_score', 'no_score', 'exclude_from_ai'],
        });

        if (($block['type'] ?? null) === 'question') {
            $questionType = $block['question_type'] ?? null;

            if (! in_array($questionType, self::CHOICE_TYPES, true)) {
                unset($block['options']);
            }
            if (! in_array($questionType, self::TEXT_TYPES, true)) {
                unset($block['max_length']);
            }
            if ($questionType !== 'yes_no') {
                unset($block['yes_score'], $block['no_score']);
            }
        }

        if (is_array($block['options'] ?? null)) {
            $block['options'] = array_values(array_map(
                fn (mixed $option): mixed => is_array($option) ? self::only($option, self::OPTION_KEYS) : $option,
                $block['options'],
            ));
        }

        if (is_array($block['visibility'] ?? null) && ! isset($block['visibility']['all'], $block['visibility']['any'])) {
            $block['visibility'] = self::only($block['visibility'], self::VISIBILITY_KEYS);
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function only(array $value, array $allowed): array
    {
        return array_intersect_key($value, array_flip($allowed));
    }

    private static function prune(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $pruned = [];
        foreach ($value as $key => $item) {
            $item = self::prune($item);
            if ($item === null || $item === [] || $item === '') {
                continue;
            }
            $pruned[$key] = $item;
        }

        return array_is_list($value) ? array_values($pruned) : $pruned;
    }
}
