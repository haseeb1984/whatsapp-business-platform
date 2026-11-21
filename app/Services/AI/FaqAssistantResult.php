<?php

namespace App\Services\AI;

class FaqAssistantResult
{
    /**
     * @param  array<int, string>  $faqIds
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $answer,
        public readonly array $faqIds,
        public readonly string $source,
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'answer' => $this->answer,
            'faq_ids' => $this->faqIds,
            'source' => $this->source,
        ];
    }
}
