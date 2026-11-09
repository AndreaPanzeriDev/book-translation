<?php

namespace App\Services\Export;

use RuntimeException;

class IncompleteTranslationException extends RuntimeException
{
    /**
     * @param  array<int, array{chapter_id: int, title: string, translated: int, total: int}>  $missingChapters
     */
    public function __construct(
        public readonly array $missingChapters,
        public readonly int $missingPages,
    ) {
        parent::__construct('The selected chapters are not fully translated.');
    }
}
