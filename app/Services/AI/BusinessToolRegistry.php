<?php

namespace App\Services\AI;

use App\Models\BookingPolicy;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Booking\AppointmentAvailabilityService;
use Throwable;

class BusinessToolRegistry
{
    public function __construct(
        private readonly BusinessIntentRouter $intents,
        private readonly AppointmentAvailabilityService $availability,
    )
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
            'list_available_slots' => $this->availableSlots($arguments, $locale),
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


    /**
     * @param  array<string, mixed>  $arguments
     */
    private function availableSlots(array $arguments, string $locale): BusinessIntentResult
    {
        $keys = array_keys($arguments);
        sort($keys);
        if (! in_array($keys, [['date', 'service_name'], ['date', 'service_name', 'staff_name']], true)
            || ! is_string($arguments['service_name'] ?? null)
            || mb_strlen(trim($arguments['service_name'])) < 2
            || mb_strlen(trim($arguments['service_name'])) > 100
            || ! is_string($arguments['date'] ?? null)
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $arguments['date']) !== 1
            || (array_key_exists('staff_name', $arguments)
                && (! is_string($arguments['staff_name'])
                    || mb_strlen(trim($arguments['staff_name'])) < 2
                    || mb_strlen(trim($arguments['staff_name'])) > 100))) {
            return $this->notHandled('invalid_tool_arguments');
        }

        $services = Service::query()
            ->where('active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($arguments['service_name']))])
            ->limit(2)
            ->get();

        if ($services->count() !== 1) {
            return $this->notHandled('service_not_found_or_ambiguous');
        }

        $staffId = null;
        if (isset($arguments['staff_name'])) {
            $staff = Staff::query()
                ->where('active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($arguments['staff_name']))])
                ->limit(2)
                ->get();

            if ($staff->count() !== 1) {
                return $this->notHandled('staff_not_found_or_ambiguous');
            }

            $staffId = $staff->first()->id;
        }

        try {
            $slots = $this->availability->availableSlots(
                $services->first()->id,
                $arguments['date'],
                $staffId,
            );
        } catch (Throwable) {
            return $this->notHandled('availability_unavailable');
        }

        $language = str_starts_with(mb_strtolower(str_replace('_', '-', $locale)), 'ur-latn')
            ? 'roman'
            : (str_starts_with(mb_strtolower($locale), 'ur') ? 'ur' : 'en');

        if ($slots === []) {
            $answer = match ($language) {
                'ur' => $arguments['date'].' کے لیے کوئی دستیاب وقت نہیں ملا۔ براہِ کرم کوئی اور تاریخ منتخب کریں۔',
                'roman' => $arguments['date'].' ke liye koi available time nahi mila. Barah-e-karam doosri tareekh dein.',
                default => 'No appointment times are available for '.$arguments['date'].'. Please choose another date.',
            };

            return new BusinessIntentResult('answered', $answer, 'available_slots');
        }

        $slots = array_slice($slots, 0, 6);
        $heading = match ($language) {
            'ur' => $arguments['date'].' کے لیے دستیاب اوقات:',
            'roman' => $arguments['date'].' ke liye available times:',
            default => 'Available appointment times for '.$arguments['date'].':',
        };
        $lines = [$heading];

        foreach ($slots as $slot) {
            $lines[] = $slot['local_start'].' — '.$slot['staff_name'];
        }

        if (! ($slots[0]['calendar_checked'] ?? false)) {
            $lines[] = match ($language) {
                'ur' => 'نوٹ: Google Calendar منسلک نہیں ہے؛ اوقات کاروبار اور عملے کے مقررہ شیڈول کے مطابق ہیں۔',
                'roman' => 'Note: Google Calendar connected nahi; times workspace aur staff schedules ke mutabiq hain.',
                default => 'Note: Google Calendar is not connected; times reflect configured business and staff schedules only.',
            };
        }

        $timezone = $slots[0]['timezone'] ?? null;
        if (is_string($timezone) && $timezone !== '') {
            $lines[] = match ($language) {
                'ur' => 'ٹائم زون: '.$timezone,
                'roman' => 'Time zone: '.$timezone,
                default => 'Time zone: '.$timezone,
            };
        }

        return new BusinessIntentResult('answered', implode("\\n", $lines), 'available_slots');
    }

    private function notHandled(string $reason): BusinessIntentResult
    {
        return new BusinessIntentResult('not_handled', null, $reason);
    }
}
