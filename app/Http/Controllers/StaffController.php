<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\TenantInvitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(): Response
    {
        $staff = Staff::query()->with('serviceAssignments')->orderBy('name')->get();

        return Inertia::render('Business/Staff/Index', [
            'staff' => $staff->map(fn (Staff $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'active' => $member->active,
                'service_ids' => $member->serviceAssignments->pluck('service_id')->all(),
            ])->all(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'invitations' => TenantInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('created_at')->get(['id', 'email', 'role', 'expires_at']),\n            'invitations' => TenantInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('created_at')->get(['id', 'email', 'role', 'expires_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        DB::transaction(function () use ($validated): void {
            $member = Staff::query()->create(collect($validated)->except('service_ids')->all());
            $this->syncServices($member, $validated['service_ids']);
        });

        return back()->with('success', 'Staff member created.');
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $validated = $this->validated($request, $staff);

        DB::transaction(function () use ($staff, $validated): void {
            $staff->fill(collect($validated)->except('service_ids')->all())->save();
            $this->syncServices($staff, $validated['service_ids']);
        });

        return back()->with('success', 'Staff member updated.');
    }

    public function destroy(Staff $staff): RedirectResponse
    {
        $staff->delete();

        return back()->with('success', 'Staff member removed.');
    }

    private function syncServices(Staff $staff, array $serviceIds): void
    {
        StaffService::query()->where('staff_id', $staff->id)->delete();

        foreach ($serviceIds as $serviceId) {
            StaffService::query()->create([
                'staff_id' => $staff->id,
                'service_id' => $serviceId,
            ]);
        }
    }

    private function validated(Request $request, ?Staff $staff = null): array
    {
        $tenantId = app(TenantContext::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
            'active' => ['required', 'boolean'],
            'service_ids' => ['present', 'array'],
            'service_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ]);
    }
}
