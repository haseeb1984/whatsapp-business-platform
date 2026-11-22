<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordAuditEvent
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        try {
            $route = $request->route();
            $parameters = $route?->parameters() ?? [];
            $subjectType = null;
            $subjectId = null;

            foreach ($parameters as $key => $value) {
                if (is_object($value) && method_exists($value, 'getKey')) {
                    $subjectType = (string) $key;
                    $subjectId = (string) $value->getKey();
                    break;
                }
                if (is_string($value) || is_int($value)) {
                    $subjectType = (string) $key;
                    $subjectId = (string) $value;
                    break;
                }
            }

            $tenantContext = $request->attributes->get(TenantContext::class);
            AuditEvent::query()->create([
                'tenant_id' => $tenantContext instanceof TenantContext ? $tenantContext->id() : null,
                'actor_user_id' => $request->user()?->getAuthIdentifier(),
                'event' => $route?->getName() ?: $request->method().' '.$request->path(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'metadata' => [
                    'method' => $request->method(),
                    'status_code' => $response->getStatusCode(),
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Admin audit event could not be recorded.', [
                'route' => $request->route()?->getName(),
                'exception' => $exception::class,
            ]);
        }

        return $response;
    }
}
