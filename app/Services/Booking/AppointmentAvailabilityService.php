<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffAvailabilityException;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AppointmentAvailabilityService
{
    /**
     * Return available local start times for an active service and eligible staff.
     *
     * @return array<int, array{staff_id: string, staff_name: string, starts_at: string, ends_at: string, local_start: string, local_end: string, utc_offset: string, timezone: string}>
     */
    public function availableSlots(string $serviceId, string $localDate, ?string $staffId = null, ?int $intervalMinutes = null): array
    {
        $policy = BookingPolicy::query()->first();
        $intervalMinutes ??= $policy?->slot_interval_minutes ?? 15;
        if ($intervalMinutes < 5 || $intervalMinutes > 60 || $intervalMinutes % 5 !== 0) {
            throw ValidationException::withMessages(['interval' => 'Slot interval must be between 5 and 60 minutes in 5-minute increments.']);
        }

        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $date = $this->parseLocalDate($localDate, $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($date->lt($today) || $date->gt($today->addDays($policy?->booking_horizon_days ?? 60))) {
            return [];
        }
        $earliestStart = CarbonImmutable::now($timezone)->addMinutes($policy?->minimum_notice_minutes ?? 60);

        $service = Service::query()->where('active', true)->find($serviceId);
        if (! $service) {
            throw ValidationException::withMessages(['service_id' => 'The selected service is unavailable.']);
        }

        $businessWindow = $this->businessWindow($date);
        if ($businessWindow === null) {
            return [];
        }

        $staffQuery = Staff::query()->where('active', true)->whereHas('serviceAssignments', function ($query) use ($service): void {
            $query->where('service_id', $service->id);
        });

        if ($staffId !== null) {
            $staffQuery->whereKey($staffId);
        }

        $staffMembers = $staffQuery->orderBy('name')->get();
        $slots = [];

        foreach ($staffMembers as $staff) {
            $staffWindow = $this->staffWindow($staff, $date);
            if ($staffWindow === null) {
                continue;
            }

            $opensAt = $businessWindow['opens_at']->greaterThan($staffWindow['opens_at'])
                ? $businessWindow['opens_at']
                : $staffWindow['opens_at'];
            $closesAt = $businessWindow['closes_at']->lessThan($staffWindow['closes_at'])
                ? $businessWindow['closes_at']
                : $staffWindow['closes_at'];

            $firstStart = $opensAt->addMinutes((int) $service->buffer_before_minutes);
            if ($earliestStart->greaterThan($firstStart)) {
                $secondsUntilNotice = $earliestStart->getTimestamp() - $firstStart->getTimestamp();
                $steps = (int) ceil($secondsUntilNotice / ($intervalMinutes * 60));
                $firstStart = $firstStart->addMinutes($steps * $intervalMinutes);
            }
            $lastStart = $closesAt->subMinutes((int) $service->duration_minutes + (int) $service->buffer_after_minutes);
            if ($firstStart->greaterThan($lastStart)) {
                continue;
            }

            for ($start = $firstStart; $start->lessThanOrEqualTo($lastStart); $start = $start->addMinutes($intervalMinutes)) {
                if ($start->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                    continue;
                }

                $end = $start->addMinutes((int) $service->duration_minutes);
                $reservedStart = $start->subMinutes((int) $service->buffer_before_minutes);
                $reservedEnd = $end->addMinutes((int) $service->buffer_after_minutes);

                if ($this->isBusy($staff->id, $reservedStart, $reservedEnd)) {
                    continue;
                }

                $slots[] = [
                    'staff_id' => $staff->id,
                    'staff_name' => $staff->name,
                    'starts_at' => $start->utc()->toIso8601String(),
                    'ends_at' => $end->utc()->toIso8601String(),
                    'local_start' => $start->format('H:i'),
                    'local_end' => $end->format('H:i'),
                    'utc_offset' => $start->format('P'),
                    'timezone' => $timezone,
                ];
            }
        }

        usort($slots, static fn (array $left, array $right): int => [$left['starts_at'], $left['staff_name']] <=> [$right['starts_at'], $right['staff_name']]);

        return $slots;
    }

    public function assertSlotAvailable(string $serviceId, string $staffId, CarbonImmutable $localStart): void
    {
        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $localStart = $localStart->setTimezone($timezone);
        $slots = $this->availableSlots($serviceId, $localStart->format('Y-m-d'), $staffId);
        $requestedUtc = $localStart->utc()->getTimestamp();

        foreach ($slots as $slot) {
            if (CarbonImmutable::parse($slot['starts_at'])->getTimestamp() === $requestedUtc) {
                return;
            }
        }

        throw ValidationException::withMessages(['starts_at' => 'That appointment time is no longer available.']);
    }

    private function businessWindow(CarbonImmutable $date): ?array
    {
        $holiday = BusinessHoliday::query()->whereDate('date', $date->toDateString())->first();
        if ($holiday) {
            if ($holiday->is_closed || ! $holiday->opens_at || ! $holiday->closes_at) {
                return null;
            }

            return $this->window($date, (string) $holiday->opens_at, (string) $holiday->closes_at);
        }

        $weekday = $date->dayOfWeekIso - 1;
        $hours = BusinessHour::query()->where('weekday', $weekday)->first();
        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return null;
        }

        return $this->window($date, (string) $hours->opens_at, (string) $hours->closes_at);
    }

    private function staffWindow(Staff $staff, CarbonImmutable $date): ?array
    {
        $exception = StaffAvailabilityException::query()
            ->where('staff_id', $staff->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($exception) {
            if ($exception->is_closed || ! $exception->opens_at || ! $exception->closes_at) {
                return null;
            }

            return $this->window($date, (string) $exception->opens_at, (string) $exception->closes_at);
        }

        $weekday = $date->dayOfWeekIso - 1;
        $hours = StaffWorkingHour::query()
            ->where('staff_id', $staff->id)
            ->where('weekday', $weekday)
            ->first();

        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return null;
        }

        return $this->window($date, (string) $hours->opens_at, (string) $hours->closes_at);
    }

    private function window(CarbonImmutable $date, string $opensAt, string $closesAt): ?array
    {
        $timezone = $date->getTimezone();
        $openTime = substr($opensAt, 0, 5);
        $closeTime = substr($closesAt, 0, 5);
        $open = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date->toDateString().' '.$openTime, $timezone);
        $close = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date->toDateString().' '.$closeTime, $timezone);

        if (! $open || ! $close
            || $open->format('Y-m-d H:i') !== $date->toDateString().' '.$openTime
            || $close->format('Y-m-d H:i') !== $date->toDateString().' '.$closeTime
            || $open->greaterThanOrEqualTo($close)) {
            return null;
        }

        return ['opens_at' => $open, 'closes_at' => $close];
    }

    private function isBusy(string $staffId, CarbonImmutable $reservedStart, CarbonImmutable $reservedEnd): bool
    {
        return Appointment::query()
            ->where('staff_id', $staffId)
            ->where(function ($query): void {
                $query->whereIn('status', ['calendar_pending', 'confirmed'])
                    ->orWhere(function ($pending): void {
                        $pending->where('status', 'pending_hold')
                            ->where('hold_expires_at', '>', now());
                    });
            })
            ->where('reserved_starts_at', '<', $reservedEnd->utc())
            ->where('reserved_ends_at', '>', $reservedStart->utc())
            ->exists();
    }

    private function parseLocalDate(string $localDate, string $timezone): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $timezone);
        if (! $date || $date->format('Y-m-d') !== $localDate) {
            throw ValidationException::withMessages(['date' => 'Provide a valid local date in YYYY-MM-DD format.']);
        }

        return $date;
    }
}
