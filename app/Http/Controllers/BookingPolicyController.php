<?php

namespace App\Http\Controllers;

use App\Models\BookingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingPolicyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'minimum_notice_minutes' => ['required', 'integer', 'between:0,10080'],
            'booking_horizon_days' => ['required', 'integer', 'between:1,365'],
            'slot_interval_minutes' => ['required', 'integer', 'between:5,60'],
            'hold_minutes' => ['required', 'integer', 'between:1,60'],
        ]);

        if ($validated['slot_interval_minutes'] % 5 !== 0) {
            throw ValidationException::withMessages(['slot_interval_minutes' => 'Slot interval must be a multiple of five minutes.']);
        }

        DB::transaction(function () use ($validated): void {
            $policy = BookingPolicy::query()->first();
            if ($policy) {
                $policy->fill($validated)->save();
            } else {
                BookingPolicy::query()->create($validated);
            }
        });

        return back()->with('success', 'Booking policy saved.');
    }
}
