<?php

namespace App\Http\Controllers;

use App\Models\BusinessHoliday;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class BusinessHolidayController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Business/Holidays/Index', [
            'holidays' => BusinessHoliday::query()
                ->orderBy('date')
                ->get()
                ->map(fn (BusinessHoliday $holiday): array => [
                    'id' => $holiday->id,
                    'date' => $holiday->date->format('Y-m-d'),
                    'name' => $holiday->name,
                    'is_closed' => $holiday->is_closed,
                    'opens_at' => $holiday->opens_at ? substr((string) $holiday->opens_at, 0, 5) : null,
                    'closes_at' => $holiday->closes_at ? substr((string) $holiday->closes_at, 0, 5) : null,
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        BusinessHoliday::query()->create($validated);

        return back()->with('success', 'Holiday saved.');
    }

    public function update(Request $request, BusinessHoliday $holiday): RedirectResponse
    {
        $validated = $this->validated($request, $holiday);
        $holiday->fill($validated)->save();

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(BusinessHoliday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }

    private function validated(Request $request, ?BusinessHoliday $holiday = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $uniqueDate = Rule::unique('business_holidays', 'date')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));
        if ($holiday !== null) {
            $uniqueDate->ignore($holiday->id);
        }

        $validator = validator($request->all(), [
            'date' => ['required', 'date_format:Y-m-d', $uniqueDate],
            'name' => ['required', 'string', 'max:160'],
            'is_closed' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'required_if:is_closed,false', 'date_format:H:i'],
            'closes_at' => ['nullable', 'required_if:is_closed,false', 'date_format:H:i'],
        ]);

        $validator->after(function (Validator $validator) use ($request): void {
            if ($request->boolean('is_closed')) {
                return;
            }

            $opens = $request->input('opens_at');
            $closes = $request->input('closes_at');
            if (is_string($opens) && is_string($closes) && $opens >= $closes) {
                $validator->errors()->add('closes_at', 'Closing time must be later than opening time.');
            }
        });

        $data = $validator->validate();
        if ($data['is_closed']) {
            $data['opens_at'] = null;
            $data['closes_at'] = null;
        }

        return $data;
    }
}
