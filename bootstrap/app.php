<?php
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RecordAuditEvent;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RequireTenantRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['tenant' => ResolveTenant::class, 'tenant.role' => RequireTenantRole::class, 'platform.admin' => EnsurePlatformAdmin::class, 'audit' => RecordAuditEvent::class]);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
