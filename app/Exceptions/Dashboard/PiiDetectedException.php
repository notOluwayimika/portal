<?php

namespace App\Exceptions\Dashboard;

use RuntimeException;

class PiiDetectedException extends RuntimeException
{
    public function __construct(string $fieldPath, string $reason)
    {
        parent::__construct("PII detected in analysis output at '{$fieldPath}': {$reason}. Aborting write.");
    }
}
