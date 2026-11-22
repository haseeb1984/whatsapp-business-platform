<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
     * Preserve efficient bulk writes while binding every row to the active tenant.
     * Query-builder inserts bypass model events, so tenant ownership must be enforced here.
     */
    public function insert(array $values): bool
    {
        return parent::insert($this->withCurrentTenant($values));
    }

    public function insertOrIgnore(array $values): int
    {
        return parent::insertOrIgnore($this->withCurrentTenant($values));
    }

    public function insertGetId(array $values, $sequence = null): int|string
    {
        return parent::insertGetId($this->withCurrentTenant($values), $sequence);
    }

    /**
     * INSERT ... SELECT cannot be safely rewritten to guarantee tenant ownership.
     */
    public function insertUsing(array $columns, $query): int
    {
        throw new LogicException('Bulk insert-from-select is disabled for tenant-owned models.');
    }

    /**
     * Upsert conflicts must include tenant_id in the unique key, otherwise a row
     * belonging to another tenant could be updated despite the model global scope.
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $rows = $this->withCurrentTenant($values);
        $uniqueColumns = (array) $uniqueBy;

        if (! in_array('tenant_id', $uniqueColumns, true)) {
            throw new LogicException('Tenant-owned upserts must include tenant_id in their unique key.');
        }

        if (is_array($update) && in_array('tenant_id', $update, true)) {
            throw new LogicException('Tenant-owned upserts cannot update tenant_id.');
        }

        return parent::upsert($rows, $uniqueBy, $update);
    }

    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        throw new LogicException('Bulk update-or-insert operations are disabled for tenant-owned models; use tenant-scoped model operations instead.');
    }

    /**
     * Normalize both single-row and multi-row insert payloads and reject spoofed
     * tenant IDs before SQL reaches the database.
     */
    private function withCurrentTenant(array $values): array
    {
        if (! app()->bound(TenantContext::class)) {
            throw new LogicException('Tenant-owned bulk writes require an explicit tenant context.');
        }

        $tenantId = app(TenantContext::class)->id();
        $isMultiRow = array_is_list($values) && isset($values[0]) && is_array($values[0]);

        if ($isMultiRow) {
            foreach ($values as &$row) {
                if (! is_array($row)) {
                    throw new LogicException('Tenant-owned bulk inserts require row arrays.');
                }

                $row = $this->withTenantId($row, $tenantId);
            }
            unset($row);

            return $values;
        }

        return $this->withTenantId($values, $tenantId);
    }

    private function withTenantId(array $row, string $tenantId): array
    {
        if (isset($row['tenant_id']) && (string) $row['tenant_id'] !== $tenantId) {
            throw new LogicException('Bulk writes cannot assign a tenant-owned record to another tenant.');
        }

        $row['tenant_id'] = $tenantId;

        return $row;
    }
}
