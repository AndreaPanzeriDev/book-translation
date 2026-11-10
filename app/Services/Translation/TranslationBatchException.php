<?php

namespace App\Services\Translation;

use RuntimeException;

class TranslationBatchException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
