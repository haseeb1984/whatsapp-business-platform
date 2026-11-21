<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'tenant' => fn () => app()->bound(TenantContext::class)
                ? [
                    'id' => app(TenantContext::class)->id(),
                    'name' => app(TenantContext::class)->tenant()->name,
                ]
                : null,
        ];
    }
}
