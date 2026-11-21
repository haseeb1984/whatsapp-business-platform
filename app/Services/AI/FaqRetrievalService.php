<?php

namespace App\Services\AI;

use App\Models\Faq;

class FaqRetrievalService
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'how', 'i', 'in', 'is',
        'it', 'me', 'my', 'of', 'on', 'or', 'our', 'please', 'the', 'to', 'we',
        'what', 'when', 'where', 'which', 'who', 'with', 'you', 'your',
    ];

    /**
     * @return array<int, array{faq: Faq, score: float}>
     */
    public function retrieve(string $question, string $locale, int $limit = 5): array
    {
        $tokens = $this->tokens($question);
        if ($tokens === []) {
            return [];
        }

        $locales = [$locale];
        if (str_contains($locale, '-')) {
            $locales[] = explode('-', $locale, 2)[0];
        }

        $faqs = Faq::query()
            ->where('status', 'published')
            ->whereIn('locale', array_unique($locales))
            ->get(['id', 'question', 'answer', 'locale']);

        $matches = [];
        foreach ($faqs as $faq) {
            $faqTokens = $this->tokens($faq->question);
            if ($faqTokens === []) {
                continue;
            }

            $overlap = count(array_intersect($tokens, $faqTokens));
            if ($overlap === 0) {
                continue;
            }

            $matches[] = [
                'faq' => $faq,
                'score' => $overlap / count($tokens),
            ];
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($matches, 0, max(1, min($limit, 10)));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($value), $matches);

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => ! in_array($token, self::STOP_WORDS, true),
        )));
    }
}
