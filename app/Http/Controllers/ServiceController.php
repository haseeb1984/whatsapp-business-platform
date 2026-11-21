<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Business/Services/Index', [
            'services' => Service::query()->orderBy('name')->get()->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price_amount' => $service->price_amount,
                'currency' => $service->currency,
                'duration_minutes' => $service->duration_minutes,
                'buffer_before_minutes' => $service->buffer_before_minutes,
                'buffer_after_minutes' => $service->buffer_after_minutes,
                'active' => $service->active,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Service::query()->create($this->validated($request));

        return back()->with('success', 'Service created.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service->fill($this->validated($request, $service))->save();

        return back()->with('success', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return back()->with('success', 'Service removed.');
    }

    private function validated(Request $request, ?Service $service = null): array
    {
        $uniqueName = Rule::unique('services', 'name')
            ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id()));
        if ($service !== null) {
            $uniqueName->ignore($service->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', $uniqueName],
            'description' => ['nullable', 'string', 'max:3000'],
            'price_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
            'buffer_before_minutes' => ['required', 'integer', 'between:0,240'],
            'buffer_after_minutes' => ['required', 'integer', 'between:0,240'],
            'active' => ['required', 'boolean'],
        ]);
    }
}
