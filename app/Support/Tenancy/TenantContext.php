<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use LogicException;

final class TenantContext
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $role,
    ) {
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
