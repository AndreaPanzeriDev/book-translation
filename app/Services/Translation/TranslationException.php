<?php

namespace App\Services\Translation;

use RuntimeException;
use Throwable;

class TranslationException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
