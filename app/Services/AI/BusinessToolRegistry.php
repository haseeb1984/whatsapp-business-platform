<?php

namespace App\Services\AI;

use App\Models\Service;

class BusinessToolRegistry
{
    public function __construct(private readonly BusinessIntentRouter $intents)
    {
    }

    /**
     * Execute only explicitly allowlisted, read-only business tools.
     *
     * @param  mixed  $arguments
     */
    public function execute(string $tool, mixed $arguments, string $locale): BusinessIntentResult
    {
        if (! is_array($arguments)) {
            return $this->notHandled('invalid_tool_arguments');
        }

        return match ($tool) {
            'list_services' => $arguments === []
                ? $this->intents->route('services', $locale)
                : $this->notHandled('invalid_tool_arguments'),
            'search_services' => $this->searchServices($arguments, $locale),
            'get_business_hours' => $this->businessHours($arguments, $locale),
            'get_business_contact' => $arguments === []
                ? $this->intents->route('contact details', $locale)
                : $this->notHandled('invalid_tool_arguments'),
            default => $this->notHandled('unknown_tool'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function searchServices(array $arguments, string $locale): BusinessIntentResult
    {
        if (array_keys($arguments) !== ['query']
            || ! is_string($arguments['query'])
            || mb_strlen(trim($arguments['query'])) < 2
            || mb_strlen(trim($arguments['query'])) > 100) {
            return $this->notHandled('invalid_tool_arguments');
        }

        $term = trim($arguments['query']);
        $escaped = addcslashes($term, '%_\\');
        $services = Service::query()
            ->where('active', true)
            ->where('name', 'like', '%'.$escaped.'%')
            ->orderBy('name')
            ->limit(5)
            ->get(['name', 'price_amount', 'currency', 'duration_minutes']);

        if ($services->isEmpty()) {
            return $this->notHandled('service_not_found');
        }

        $language = str_starts_with(mb_strtolower(str_replace('_', '-', $locale)), 'ur-latn')
            ? 'roman'
            : (str_starts_with(mb_strtolower($locale), 'ur') ? 'ur' : 'en');
        $heading = match ($language) {
            'ur' => 'متعلقہ خدمات یہ ہیں:',
            'roman' => 'Milti julti services yeh hain:',
            default => 'Matching services:',
        };
        $minuteLabel = match ($language) {
            'ur' => 'منٹ',
            'roman' => 'minute',
            default => 'minutes',
        };

        $lines = [$heading];
        foreach ($services as $service) {
            $lines[] = $service->name.' — '.$service->price_amount.' '.$service->currency
                .' ('.$service->duration_minutes.' '.$minuteLabel.')';
        }

        return new BusinessIntentResult('answered', implode("\n", $lines), 'service_search');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function businessHours(array $arguments, string $locale): BusinessIntentResult
    {
        if (array_keys($arguments) !== ['period']
            || ! is_string($arguments['period'])
            || ! in_array($arguments['period'], ['today', 'tomorrow', 'week'], true)) {
            return $this->notHandled('invalid_tool_arguments');
        }

        $message = match ($arguments['period']) {
            'today' => 'business hours today',
            'tomorrow' => 'business hours tomorrow',
            default => 'business hours',
        };

        return $this->intents->route($message, $locale);
    }

    private function notHandled(string $reason): BusinessIntentResult
    {
        return new BusinessIntentResult('not_handled', null, $reason);
    }
}
