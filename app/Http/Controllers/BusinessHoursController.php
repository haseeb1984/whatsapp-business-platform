<?php

namespace App\Http\Controllers;

use App\Models\BusinessHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BusinessHoursController extends Controller
{
    private const DAYS = [
        0 => 'Monday',
        1 => 'Tuesday',
        2 => 'Wednesday',
        3 => 'Thursday',
        4 => 'Friday',
        5 => 'Saturday',
        6 => 'Sunday',
    ];

    public function edit(): Response
    {
        $stored = BusinessHour::query()->get()->keyBy('weekday');

        return Inertia::render('Business/Hours/Edit', [
            'days' => collect(self::DAYS)->map(fn (string $label, int $weekday): array => [
                'weekday' => $weekday,
                'label' => $label,
                'is_closed' => $stored->has($weekday) ? $stored[$weekday]->is_closed : $weekday === 6,
                'opens_at' => $stored->get($weekday)?->opens_at ? substr((string) $stored[$weekday]->opens_at, 0, 5) : '09:00',
                'closes_at' => $stored->get($weekday)?->closes_at ? substr((string) $stored[$weekday]->closes_at, 0, 5) : '17:00',
            ])->values()->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $days = $request->input('days', []);
            $weekdays = array_map(static fn ($day) => $day['weekday'] ?? null, $days);

            if (count(array_unique($weekdays, SORT_REGULAR)) !== 7 || array_diff(range(0, 6), $weekdays) !== []) {
                $validator->errors()->add('days', 'Provide each weekday exactly once.');
                return;
            }

            foreach ($days as $index => $day) {
                if (! is_array($day) || ($day['is_closed'] ?? false)) {
                    continue;
                }

                $opens = $day['opens_at'] ?? null;
                $closes = $day['closes_at'] ?? null;

                if (! is_string($opens) || ! is_string($closes) || $opens >= $closes) {
                    $validator->errors()->add("days.{$index}.closes_at", 'Closing time must be later than opening time.');
                }
            }
        });

        $validated = $validator->validate();

        DB::transaction(function () use ($validated): void {
            foreach ($validated['days'] as $day) {
                BusinessHour::query()->updateOrCreate(
                    ['weekday' => $day['weekday']],
                    [
                        'is_closed' => $day['is_closed'],
                        'opens_at' => $day['is_closed'] ? null : $day['opens_at'],
                        'closes_at' => $day['is_closed'] ? null : $day['closes_at'],
                    ],
                );
            }
        });

        return back()->with('success', 'Business hours saved.');
    }
}
