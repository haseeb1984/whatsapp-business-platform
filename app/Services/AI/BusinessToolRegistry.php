<?php

namespace App\Services\AI;

use App\Models\BookingPolicy;
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
            'get_booking_policy' => $arguments === []
                ? $this->bookingPolicy($locale)
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
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($escaped).'%'])
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


    private function bookingPolicy(string $locale): BusinessIntentResult
    {
        $policy = BookingPolicy::query()->first();
        $minimumNotice = $policy?->minimum_notice_minutes ?? 60;
        $bookingHorizon = $policy?->booking_horizon_days ?? 60;
        $slotInterval = $policy?->slot_interval_minutes ?? 15;

        $language = str_starts_with(mb_strtolower(str_replace('_', '-', $locale)), 'ur-latn')
            ? 'roman'
            : (str_starts_with(mb_strtolower($locale), 'ur') ? 'ur' : 'en');

        $answer = match ($language) {
            'ur' => "بکنگ کے اصول: کم از کم پیشگی اطلاع {$minimumNotice} منٹ ہے، بکنگ {$bookingHorizon} دن پہلے تک کی جا سکتی ہے، اور دستیاب اوقات {$slotInterval} منٹ کے وقفے سے ہوتے ہیں۔",
            'roman' => "Booking ke qawaid: kam az kam {$minimumNotice} minute pehle booking zaroori hai, booking {$bookingHorizon} din pehle tak ki ja sakti hai, aur slots {$slotInterval} minute ke waqfay se hain.",
            default => "Booking policy: please book at least {$minimumNotice} minutes ahead. Appointments can be booked up to {$bookingHorizon} days in advance, with slots offered every {$slotInterval} minutes.",
        };

        return new BusinessIntentResult('answered', $answer, 'booking_policy');
    }

    private function notHandled(string $reason): BusinessIntentResult
    {
        return new BusinessIntentResult('not_handled', null, $reason);
    }
}
