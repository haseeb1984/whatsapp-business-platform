<?php

namespace App\Services\AI;

class BusinessIntentResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $answer = null,
        public readonly string $intent = 'unknown',
    ) {
    }
}
