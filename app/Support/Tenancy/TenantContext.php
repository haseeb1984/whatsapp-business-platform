<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use LogicException;

final class TenantContext
{
    public function __construct(private readonly Tenant $tenant)
    {
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function id(): string
    {
        return (string) $this->tenant->getKey();
    }

    public function requireTenantId(string $tenantId): void
    {
        if ($tenantId !== $this->id()) {
            throw new LogicException('The requested resource is outside the active tenant context.');
        }
    }
}
