<?php

namespace App\Domain\Quiz;

/** Session-backed stand-in for the public runner while previewing a draft. */
final class DraftPreviewState
{
    /**
     * @param  array<string, mixed>  $answers_snapshot
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $current_page = 0,
        public array $answers_snapshot = [],
        public array $metadata = [],
    ) {}
}
