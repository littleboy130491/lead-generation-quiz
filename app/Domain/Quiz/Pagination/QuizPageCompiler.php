<?php

namespace App\Domain\Quiz\Pagination;

use InvalidArgumentException;

class QuizPageCompiler
{
    public function compile(array $definition): array
    {
        $pages = [[]];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'page_break') {
                if (empty(end($pages))) {
                    throw new InvalidArgumentException('Page breaks cannot be leading, trailing, or consecutive.');
                } $pages[] = [];

                continue;
            } $pages[array_key_last($pages)][] = $block;
        } if (empty(end($pages))) {
            throw new InvalidArgumentException('Page breaks cannot be leading, trailing, or consecutive.');
        }

return $pages;
    }
}
