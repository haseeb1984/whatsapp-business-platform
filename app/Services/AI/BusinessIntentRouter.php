<?php

namespace App\Services\AI;

use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class BusinessIntentRouter
{
    public function route(string $message, string $locale): BusinessIntentResult
    {
        $message = mb_strtolower(trim($message));
        if ($message === '' || ! str_starts_with(mb_strtolower($locale), 'en')) {
            return new BusinessIntentResult('not_handled');
        }

        if (preg_match('/\b(price|pricing|cost|fee|fees|how much|rate|charges)\b/u', $message) === 1) {
            return $this->services(true);
        }

        if (preg_match('/\b(service|services|offer|provide|menu|treatment|what do you do)\b/u', $message) === 1) {
            return $this->services(false);
        }

        if (preg_match('/\b(open|opening|hours|timing|timings|close|closing|working hours)\b/u', $message) === 1) {
            return $this->hours($message);
        }

        if (preg_match('/\b(address|location|where are you|directions|contact|phone|email|website)\b/u', $message) === 1) {
            return $this->contact();
        }

        return new BusinessIntentResult('not_handled');
    }

    private function services(bool $includePrices): BusinessIntentResult
    {
        $services = Service::query()->where('active', true)->orderBy('name')->limit(12)->get();
        if ($services->isEmpty()) {
            return new BusinessIntentResult('handoff', null, 'services_not_configured');
        }

        $lines = [$includePrices ? 'Here are our current services and prices:' : 'Here are the services we currently offer:'];
        foreach ($services as $service) {
            $line = $service->name;
            if ($includePrices) {
                $line .= ' — '.$service->price_amount.' '.$service->currency;
            }
            $line .= ' ('.$service->duration_minutes.' minutes)';
            $lines[] = $line;
        }

        return new BusinessIntentResult('answered', implode("\n", $lines), $includePrices ? 'service_pricing' : 'service_catalog');
    }

    private function hours(string $message): BusinessIntentResult
    {
        $tenant = app(TenantContext::class)->tenant();
        $timezone = $tenant->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $isToday = preg_match('/\b(today|now|currently|right now)\b/u', $message) === 1;
        $isTomorrow = preg_match('/\btomorrow\b/u', $message) === 1;

        if ($isToday || $isTomorrow) {
            $date = $isTomorrow ? $today->addDay() : $today;
            $label = $isTomorrow ? 'Tomorrow' : 'Today';
            $holiday = BusinessHoliday::query()->whereDate('date', $date->toDateString())->first();
            if ($holiday) {
                if ($holiday->is_closed) {
                    return new BusinessIntentResult('answered', 'We are closed '.strtolower($label).' '.($holiday->name ? 'for '.$holiday->name : '').'.', $isTomorrow ? 'tomorrow_hours' : 'today_hours');
                }
                if ($holiday->opens_at && $holiday->closes_at) {
                    return new BusinessIntentResult('answered', $label.'’s hours'.($holiday->name ? ' ('.$holiday->name.')' : '').' are '.substr((string) $holiday->opens_at, 0, 5).'–'.substr((string) $holiday->closes_at, 0, 5).' '.$timezone.'.', ($isTomorrow ? 'tomorrow_hours' : 'today_hours'));
                }
            }

            $hours = BusinessHour::query()->where('weekday', $date->dayOfWeekIso - 1)->first();
            if (! $hours) {
                return new BusinessIntentResult('handoff', null, 'business_hours_not_configured');
            }
            if ($hours->is_closed) {
                return new BusinessIntentResult('answered', 'We are closed '.strtolower($label).'.', $isTomorrow ? 'tomorrow_hours' : 'today_hours');
            }

            $open = substr((string) $hours->opens_at, 0, 5);
            $close = substr((string) $hours->closes_at, 0, 5);
            if ($isTomorrow) {
                return new BusinessIntentResult('answered', 'Tomorrow’s hours are '.$open.'–'.$close.' '.$timezone.'.', 'tomorrow_hours');
            }

            $currentlyOpen = $today->format('H:i') >= $open && $today->format('H:i') < $close;
            return new BusinessIntentResult(
                'answered',
                'Today’s hours are '.$open.'–'.$close.' '.$timezone.'. We are '.($currentlyOpen ? 'currently open.' : 'currently closed.'),
                'today_hours',
            );
        }

        $weeklyHours = BusinessHour::query()->orderBy('weekday')->get();
        if ($weeklyHours->isEmpty()) {
            return new BusinessIntentResult('handoff', null, 'business_hours_not_configured');
        }

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $lines = ['Our regular business hours are:'];
        foreach ($weeklyHours as $hours) {
            $day = $days[$hours->weekday] ?? 'Day '.$hours->weekday;
            $lines[] = $day.': '.($hours->is_closed ? 'Closed' : substr((string) $hours->opens_at, 0, 5).'–'.substr((string) $hours->closes_at, 0, 5));
        }
        $lines[] = 'Timezone: '.$timezone.'.';

        return new BusinessIntentResult('answered', implode("\n", $lines), 'weekly_hours');
    }

    private function contact(): BusinessIntentResult
    {
        $profile = BusinessProfile::query()->first();
        if (! $profile) {
            return new BusinessIntentResult('handoff', null, 'business_contact_not_configured');
        }

        $lines = [];
        foreach ([
            'Address' => $profile->address,
            'Phone' => $profile->phone,
            'Email' => $profile->email,
            'Website' => $profile->website,
        ] as $label => $value) {
            if (is_string($value) && trim($value) !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        if ($lines === []) {
            return new BusinessIntentResult('handoff', null, 'business_contact_not_configured');
        }

        return new BusinessIntentResult('answered', implode("\n", $lines), 'business_contact');
    }
}
