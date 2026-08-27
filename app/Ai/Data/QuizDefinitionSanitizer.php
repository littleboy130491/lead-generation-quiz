<?php

namespace App\Ai\Data;

/**
 * Strict structured output forces providers to emit every schema property, so
 * unused optional fields arrive as null or as empty collections. The persisted
 * definition contract rejects fields that do not belong to a block type, so
 * those placeholders are removed before validation.
 */
final class QuizDefinitionSanitizer
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function sanitize(array $definition): array
    {
        /** @var array<string, mixed> $sanitized */
        $sanitized = self::prune($definition);

        return $sanitized;
    }

    private static function prune(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $pruned = [];
        foreach ($value as $key => $item) {
            $item = self::prune($item);
            if ($item === null || $item === []) {
                continue;
            }
            $pruned[$key] = $item;
        }

        return array_is_list($value) ? array_values($pruned) : $pruned;
    }
}
