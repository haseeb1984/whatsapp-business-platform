<?php

namespace App\Models\Concerns;

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
}
