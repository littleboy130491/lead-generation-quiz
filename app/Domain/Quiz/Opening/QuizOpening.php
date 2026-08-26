<?php

namespace App\Domain\Quiz\Opening;

final class QuizOpening
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{html: string, start_button_label: string, hide_start_button: bool}|null
     */
    public static function fromDefinition(array $definition): ?array
    {
        $opening = $definition['opening'] ?? null;
        if (! is_array($opening)) {
            return null;
        }

        $html = trim((string) ($opening['html'] ?? ''));
        if ($html === '') {
            return null;
        }

        $label = trim((string) ($opening['start_button_label'] ?? ''));

        return [
            'html' => $html,
            'start_button_label' => $label !== '' ? $label : 'Start quiz',
            'hide_start_button' => (bool) ($opening['hide_start_button'] ?? false),
        ];
    }

    /** @param  array<string, mixed>  $definition */
    public static function isGated(array $definition): bool
    {
        $opening = self::fromDefinition($definition);

        return $opening !== null && ! $opening['hide_start_button'];
    }

    /** @param  array<string, mixed>  $definition */
    public static function isPending(array $definition, object $submission): bool
    {
        return self::isGated($definition) && ! (bool) data_get($submission->metadata ?? null, 'opening_dismissed');
    }

    /** @param  array<string, mixed>  $definition */
    public static function isInlineOnFirstPage(array $definition, object $submission): bool
    {
        $opening = self::fromDefinition($definition);

        return $opening !== null
            && $opening['hide_start_button']
            && (int) ($submission->current_page ?? 0) === 0
            && ! self::isPending($definition, $submission);
    }
}
