<?php

namespace App\Ai\Data;

/**
 * Repairs only invalid page-break boundaries emitted by a generation model.
 * All content and question blocks retain their original order and payload.
 */
final class QuizDefinitionPageBreakNormalizer
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function normalize(array $definition): array
    {
        $blocks = $definition['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return $definition;
        }

        $normalized = [];
        foreach ($blocks as $block) {
            $isPageBreak = is_array($block) && ($block['type'] ?? null) === 'page_break';
            $previousIsPageBreak = $normalized !== []
                && is_array($normalized[array_key_last($normalized)])
                && ($normalized[array_key_last($normalized)]['type'] ?? null) === 'page_break';

            if ($isPageBreak && ($normalized === [] || $previousIsPageBreak)) {
                continue;
            }

            $normalized[] = $block;
        }

        if ($normalized !== []) {
            $last = $normalized[array_key_last($normalized)];
            if (is_array($last) && ($last['type'] ?? null) === 'page_break') {
                array_pop($normalized);
            }
        }

        $definition['blocks'] = array_values($normalized);

        return $definition;
    }
}
