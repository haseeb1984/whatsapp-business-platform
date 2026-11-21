<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'staff_members';

    protected $fillable = ['name', 'email', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function serviceAssignments(): HasMany
    {
        return $this->hasMany(StaffService::class);
    }
}
