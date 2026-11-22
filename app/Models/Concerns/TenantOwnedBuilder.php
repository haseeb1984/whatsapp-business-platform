<?php

namespace App\\Models\\Concerns;

use Illuminate\\Database\\Eloquent\\Builder;
use LogicException;

class TenantOwnedBuilder extends Builder
{
    /**
     * Bulk Eloquent updates bypass model events, so block tenant reassignment here too.
     */
    public function update(array $values): int
    {
        if (array_key_exists('tenant_id', $values)) {
            throw new LogicException('Bulk updates cannot change a tenant-owned record tenant_id.');
        }

        return parent::update($values);
    }

    /**
     * Bulk inserts bypass the model creating event that assigns and validates tenant_id.
     * Require normal model creation so every inserted row is bound to the active tenant.
     */
    public function insert(array $values): bool
    {
        throw new LogicException('Bulk inserts are disabled for tenant-owned models; create model instances instead.');
    }

    public function insertOrIgnore(array $values): int
    {
        throw new LogicException('Bulk inserts are disabled for tenant-owned models; create model instances instead.');
    }

    public function insertGetId(array $values, $sequence = null): int|string
    {
        throw new LogicException('Bulk inserts are disabled for tenant-owned models; create model instances instead.');
    }

    public function insertUsing(array $columns, $query): int
    {
        throw new LogicException('Bulk inserts are disabled for tenant-owned models; create model instances instead.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('Bulk upserts are disabled for tenant-owned models; use tenant-scoped model operations instead.');
    }

    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        throw new LogicException('Bulk update-or-insert operations are disabled for tenant-owned models; use tenant-scoped model operations instead.');
    }
}
