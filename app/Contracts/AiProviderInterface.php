<?php

namespace App\Contracts;

interface AiProviderInterface
{
    /**
     * Generate a bounded completion from explicitly supplied messages.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, int|float|bool|string>  $options
     */
    public function generate(array $messages, array $options = []): string;
}
