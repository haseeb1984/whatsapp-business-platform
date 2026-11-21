<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        $tenantId = $request->session()->get('current_tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            $activeMemberships = $user->tenants()
                ->wherePivot('status', 'active')
                ->orderBy('tenants.name')
                ->get();

            if ($activeMemberships->count() === 1) {
                $tenantId = (string) $activeMemberships->first()->getKey();
                $request->session()->put('current_tenant_id', $tenantId);
            } else {
                abort($activeMemberships->isEmpty() ? 403 : 409, 'Select an active business workspace.');
            }
        }

        $tenant = $user->tenants()
            ->wherePivot('status', 'active')
            ->where('tenants.id', $tenantId)
            ->first();

        // Deliberately return 404 for unknown and non-member tenant IDs.
        abort_unless($tenant instanceof Tenant, 404);

        $context = new TenantContext($tenant);
        app()->instance(TenantContext::class, $context);
        $request->attributes->set(TenantContext::class, $context);

        return $next($request);
    }
}
