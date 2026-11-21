<?php

namespace App\Exceptions;

use RuntimeException;

class MetaWhatsAppApiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $outcomeUnknown = false)
    {
        parent::__construct($message);
    }
}
