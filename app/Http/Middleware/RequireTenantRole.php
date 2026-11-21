<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(app()->bound(TenantContext::class), 403);

        $context = app(TenantContext::class);
        abort_unless($context->hasAnyRole($roles), 403);

        return $next($request);
    }
}
