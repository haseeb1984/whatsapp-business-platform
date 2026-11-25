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
        $locale = mb_strtolower(str_replace('_', '-', $locale));
        $language = str_starts_with($locale, 'ur-latn') ? 'roman' : (str_starts_with($locale, 'ur') ? 'ur' : 'en');

        if ($message === '' || ($language === 'en' && ! str_starts_with($locale, 'en'))) {
            return new BusinessIntentResult('not_handled');
        }

        if ($this->matches($message, [
            '/\b(price|pricing|cost|fee|fees|how much|rate|charges)\b/u',
            '/\b(kitna|kitni|kitne|kitnay|qeemat|keemat|daam|paisay|paise|charges|rate)\b/u',
            '/\b(kitnay ka|kitnay ki|kitne ka|kitne ki|qeemat kya|price kya|rate kya|charges kitne)\b/u',
            '/(قیمت|کتنے|کتنی|فیس|چارجز|دام)/u',
        ])) {
            return $this->services(true, $language);
        }

        if ($this->matches($message, [
            '/\b(service|services|offer|provide|menu|treatment|what do you do)\b/u',
            '/\b(kya services|konsi service|kaun si service|konsi services|kon kon si|kya kya|services hain|kya offer)\b/u',
            '/(سروس|سروسز|خدمات|کون سی سہولت|کیا کیا کرتے)/u',
        ])) {
            return $this->services(false, $language);
        }

        if ($this->matches($message, [
            '/\b(open|opening|hours|timing|timings|close|closing|working hours)\b/u',
            '/\b(kab khult|kab band|khulay|khule|band hot|timing|timings kya|kis waqt|kab open|kab close|auqat|waqt)\b/u',
            '/(اوقات|ٹائمنگ|کھلے|کھلتے|بند|وقت|کاروباری اوقات)/u',
        ])) {
            return $this->hours($message, $language);
        }

        if ($this->matches($message, [
            '/\b(address|location|where are you|directions|contact|phone|email|website)\b/u',
            '/\b(pata|kahan|kidhar|rabta|raabta|phone number|phone no|number kya|contact number|whatsapp number|address|location)\b/u',
            '/(پتہ|کہاں|رابطہ|رابطہ نمبر|نمبر کیا|واٹس ایپ نمبر|فون|ای میل|ویب سائٹ|لوکیشن)/u',
        ])) {
            return $this->contact($language);
        }

        return new BusinessIntentResult('not_handled');
    }

    private function matches(string $message, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function services(bool $includePrices, string $language): BusinessIntentResult
    {
        $services = Service::query()->where('active', true)->orderBy('name')->limit(12)->get();
        if ($services->isEmpty()) {
            return new BusinessIntentResult('not_handled', null, 'services_not_configured');
        }

        $lines = [$this->copy($language,
            $includePrices ? 'Here are our current services and prices:' : 'Here are the services we currently offer:',
            $includePrices ? 'ہماری موجودہ خدمات اور قیمتیں یہ ہیں:' : 'ہماری دستیاب خدمات یہ ہیں:',
            $includePrices ? 'Hamari mojooda services aur qeematain yeh hain:' : 'Hamari dastiyab services yeh hain:'
        )];

        foreach ($services as $service) {
            $line = $service->name;
            if ($includePrices) {
                $line .= ' — '.$service->price_amount.' '.$service->currency;
            }
            $line .= ' ('.$service->duration_minutes.' '.$this->copy($language, 'minutes', 'منٹ', 'minute').')';
            $lines[] = $line;
        }

        return new BusinessIntentResult('answered', implode("\n", $lines), $includePrices ? 'service_pricing' : 'service_catalog');
    }

    private function hours(string $message, string $language): BusinessIntentResult
    {
        $tenant = app(TenantContext::class)->tenant();
        $timezone = $tenant->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $isToday = $this->matches($message, ['/\\b(today|now|currently|right now|aaj|aj)\\b/u', '/(آج|ابھی)/u']);
        $isTomorrow = $this->matches($message, ['/\\b(tomorrow|kal)\\b/u', '/(کل)/u']);

        if ($isToday || $isTomorrow) {
            $date = $isTomorrow ? $today->addDay() : $today;
            $label = $this->copy($language, $isTomorrow ? 'Tomorrow' : 'Today', $isTomorrow ? 'کل' : 'آج', $isTomorrow ? 'Kal' : 'Aaj');
            $holiday = BusinessHoliday::query()->whereDate('date', $date->toDateString())->first();
            if ($holiday) {
                if ($holiday->is_closed) {
                    $suffix = $holiday->name ? $this->copy($language, ' for '.$holiday->name, '، '.$holiday->name.' کی وجہ سے', '، '.$holiday->name.' ki wajah se') : '';
                    $englishLabel = $language === 'en' ? mb_strtolower($label) : $label;
                    return new BusinessIntentResult('answered', $this->copy($language, 'We are closed '.$englishLabel.$suffix.'.', $label.' چھٹی ہے'.$suffix.'۔', $label.' chhutti hai'.$suffix.'.'), $isTomorrow ? 'tomorrow_hours' : 'today_hours');
                }
                if ($holiday->opens_at && $holiday->closes_at) {
                    $hours = substr((string) $holiday->opens_at, 0, 5).'–'.substr((string) $holiday->closes_at, 0, 5);
                    $answer = $this->copy($language, $label.'’s hours'.($holiday->name ? ' ('.$holiday->name.')' : '').' are '.$hours.' '.$timezone.'.', $label.' کے اوقات '.$hours.' ہیں۔ ٹائم زون: '.$timezone.'۔', $label.' ke auqat '.$hours.' hain. Timezone: '.$timezone.'.');
                    return new BusinessIntentResult('answered', $answer, $isTomorrow ? 'tomorrow_hours' : 'today_hours');
                }
            }

            $hours = BusinessHour::query()->where('weekday', $date->dayOfWeekIso - 1)->first();
            if (! $hours) {
                return new BusinessIntentResult('not_handled', null, 'business_hours_not_configured');
            }
            if ($hours->is_closed) {
                return new BusinessIntentResult('answered', $this->copy($language, 'We are closed '.$label.'.', $label.' ہم بند ہیں۔', $label.' hum band hain.'), $isTomorrow ? 'tomorrow_hours' : 'today_hours');
            }

            $open = substr((string) $hours->opens_at, 0, 5);
            $close = substr((string) $hours->closes_at, 0, 5);
            if ($isTomorrow) {
                return new BusinessIntentResult('answered', $this->copy($language, 'Tomorrow’s hours are '.$open.'–'.$close.' '.$timezone.'.', 'کل ہمارے اوقات '.$open.' سے '.$close.' تک ہیں۔ ٹائم زون: '.$timezone.'۔', 'Kal hamaray auqat '.$open.' se '.$close.' tak hain. Timezone: '.$timezone.'.'), 'tomorrow_hours');
            }

            $currentlyOpen = $today->format('H:i') >= $open && $today->format('H:i') < $close;
            $state = $currentlyOpen
                ? $this->copy($language, 'currently open', 'اس وقت کھلے ہیں', 'is waqt khulay hain')
                : $this->copy($language, 'currently closed', 'اس وقت بند ہیں', 'is waqt band hain');

            return new BusinessIntentResult(
                'answered',
                $this->copy($language, 'Today’s hours are '.$open.'–'.$close.' '.$timezone.'. We are '.$state.'.', 'آج ہمارے اوقات '.$open.' سے '.$close.' تک ہیں۔ ہم '.$state.'۔', 'Aaj hamaray auqat '.$open.' se '.$close.' tak hain. Hum '.$state.'.'),
                'today_hours',
            );
        }

        $weeklyHours = BusinessHour::query()->orderBy('weekday')->get();
        if ($weeklyHours->isEmpty()) {
            return new BusinessIntentResult('not_handled', null, 'business_hours_not_configured');
        }

        $days = match ($language) {
            'ur' => ['پیر', 'منگل', 'بدھ', 'جمعرات', 'جمعہ', 'ہفتہ', 'اتوار'],
            'roman' => ['Peer', 'Mangal', 'Budh', 'Jumeraat', 'Jumma', 'Hafta', 'Itwaar'],
            default => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        };
        $lines = [$this->copy($language, 'Our regular business hours are:', 'ہمارے معمول کے کاروباری اوقات یہ ہیں:', 'Hamaaray mamool ke auqat yeh hain:')];
        foreach ($weeklyHours as $hours) {
            $day = $days[$hours->weekday] ?? 'Day '.$hours->weekday;
            $value = $hours->is_closed
                ? $this->copy($language, 'Closed', 'بند', 'Band')
                : substr((string) $hours->opens_at, 0, 5).'–'.substr((string) $hours->closes_at, 0, 5);
            $lines[] = $day.': '.$value;
        }
        $lines[] = $this->copy($language, 'Timezone: '.$timezone.'.', 'ٹائم زون: '.$timezone.'۔', 'Timezone: '.$timezone.'.');

        return new BusinessIntentResult('answered', implode("\n", $lines), 'weekly_hours');
    }

    private function contact(string $language): BusinessIntentResult
    {
        $profile = BusinessProfile::query()->first();
        if (! $profile) {
            return new BusinessIntentResult('not_handled', null, 'business_contact_not_configured');
        }

        $labels = match ($language) {
            'ur' => ['Address' => 'پتہ', 'Phone' => 'فون', 'Email' => 'ای میل', 'Website' => 'ویب سائٹ'],
            'roman' => ['Address' => 'Pata', 'Phone' => 'Phone', 'Email' => 'Email', 'Website' => 'Website'],
            default => ['Address' => 'Address', 'Phone' => 'Phone', 'Email' => 'Email', 'Website' => 'Website'],
        };

        $lines = [];
        foreach ([
            'Address' => $profile->address,
            'Phone' => $profile->phone,
            'Email' => $profile->email,
            'Website' => $profile->website,
        ] as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $lines[] = $labels[$key].': '.$value;
            }
        }

        if ($lines === []) {
            return new BusinessIntentResult('not_handled', null, 'business_contact_not_configured');
        }

        return new BusinessIntentResult('answered', implode("\n", $lines), 'business_contact');
    }

    private function copy(string $language, string $english, string $urdu, string $roman): string
    {
        return match ($language) {
            'ur' => $urdu,
            'roman' => $roman,
            default => $english,
        };
    }
}
