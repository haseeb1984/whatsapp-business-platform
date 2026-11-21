<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffWorkingHour extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = ['staff_id', 'weekday', 'is_closed', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }
}
