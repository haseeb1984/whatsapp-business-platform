<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;
use LogicException;

final class TenantContext
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $role,
    ) {
    }

    /**
     * Execute one background unit of work with an explicit, active tenant context.
     * The previous context is restored even when the callback throws.
     */
    public static function runForTenant(string $tenantId, Closure $callback): mixed
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        if ($tenant->status !== 'active') {
            throw new LogicException('Cannot process work for an inactive tenant.');
        }

        $hadPrevious = app()->bound(self::class);
        $previous = $hadPrevious ? app(self::class) : null;
        app()->instance(self::class, new self($tenant, 'system'));

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                app()->instance(self::class, $previous);
            } else {
                app()->forgetInstance(self::class);
            }
        }
    }

    public function tenant(): Tenant { return $this->tenant; }
    public function id(): string { return (string) $this->tenant->getKey(); }
    public function role(): string { return $this->role; }

    /** @param array<int, string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function requireTenantId(string $tenantId): void
    {
        if ($tenantId !== $this->id()) {
            throw new LogicException('The requested resource is outside the active tenant context.');
        }
    }
}
