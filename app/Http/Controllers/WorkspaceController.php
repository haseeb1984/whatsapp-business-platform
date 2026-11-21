<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaces = $request->user()->tenants()
            ->wherePivot('status', 'active')
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.name', 'tenants.slug', 'tenants.timezone']);

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
            'currentTenantId' => $request->session()->get('current_tenant_id'),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid']]);

        $membership = $request->user()->tenants()
            ->wherePivot('status', 'active')
            ->where('tenants.id', $validated['tenant_id'])
            ->first();

        abort_unless($membership instanceof Tenant, 404);
        $request->session()->put('current_tenant_id', (string) $membership->getKey());

        return redirect()->route('dashboard')->with('status', 'Workspace switched.');
    }
}
