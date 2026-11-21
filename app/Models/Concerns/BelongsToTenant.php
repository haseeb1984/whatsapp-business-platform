<?php

namespace App\\Models\\Concerns;

use App\\Support\\Tenancy\\TenantContext;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Database\\Eloquent\\Model;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (! app()->bound(TenantContext::class)) {
                throw new LogicException('Tenant-owned queries require an explicit tenant context.');
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                app(TenantContext::class)->id(),
            );
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId !== null) {
                $context->requireTenantId((string) $tenantId);
            }

            $model->setAttribute('tenant_id', $context->id());
        });

        static::updating(function (Model $model): void {
            $context = app(TenantContext::class);
            $context->requireTenantId((string) $model->getOriginal('tenant_id'));
            $context->requireTenantId((string) $model->getAttribute('tenant_id'));
        });
    }
}
