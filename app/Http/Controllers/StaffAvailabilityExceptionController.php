<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\StaffAvailabilityException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class StaffAvailabilityExceptionController extends Controller
{
    public function index(string $staffId): Response
    {
        $staff = Staff::query()->findOrFail($staffId);
        $exceptions = StaffAvailabilityException::query()
            ->where('staff_id', $staff->id)
            ->orderBy('date')
            ->get();

        return Inertia::render('Business/Staff/Exceptions', [
            'staff' => ['id' => $staff->id, 'name' => $staff->name],
            'exceptions' => $exceptions->map(fn (StaffAvailabilityException $exception): array => [
                'id' => $exception->id,
                'date' => $exception->date->format('Y-m-d'),
                'is_closed' => $exception->is_closed,
                'opens_at' => $exception->opens_at ? substr((string) $exception->opens_at, 0, 5) : null,
                'closes_at' => $exception->closes_at ? substr((string) $exception->closes_at, 0, 5) : null,
                'reason' => $exception->reason,
            ])->all(),
        ]);
    }

    public function store(Request $request, string $staffId): RedirectResponse
    {
        $staff = Staff::query()->findOrFail($staffId);
        $validated = $this->validated($request, $staff);
        StaffAvailabilityException::query()->create([
            'staff_id' => $staff->id,
            ...$validated,
        ]);

        return back()->with('success', 'Staff availability exception saved.');
    }

    public function update(Request $request, string $staffId, string $exceptionId): RedirectResponse
    {
        $staff = Staff::query()->findOrFail($staffId);
        $exception = StaffAvailabilityException::query()
            ->where('staff_id', $staff->id)
            ->findOrFail($exceptionId);
        $validated = $this->validated($request, $staff, $exception);
        $exception->fill($validated)->save();

        return back()->with('success', 'Staff availability exception updated.');
    }

    public function destroy(string $staffId, string $exceptionId): RedirectResponse
    {
        $staff = Staff::query()->findOrFail($staffId);
        StaffAvailabilityException::query()
            ->where('staff_id', $staff->id)
            ->findOrFail($exceptionId)
            ->delete();

        return back()->with('success', 'Staff availability exception removed.');
    }

    private function validated(Request $request, Staff $staff, ?StaffAvailabilityException $exception = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $uniqueDate = Rule::unique('staff_availability_exceptions', 'date')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('staff_id', $staff->id));
        if ($exception) {
            $uniqueDate->ignore($exception->id);
        }

        $validator = validator($request->all(), [
            'date' => ['required', 'date_format:Y-m-d', $uniqueDate],
            'is_closed' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        $validator->after(function (Validator $validator) use ($request): void {
            if ($request->boolean('is_closed')) {
                return;
            }

            $opens = $request->input('opens_at');
            $closes = $request->input('closes_at');
            if (! is_string($opens) || ! is_string($closes) || $opens >= $closes) {
                $validator->errors()->add('closes_at', 'Provide same-day closing time later than opening time.');
            }
        });

        return $validator->validate();
    }
}
