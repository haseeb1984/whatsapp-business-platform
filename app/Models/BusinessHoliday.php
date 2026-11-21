<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessHoliday extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = ['date', 'name', 'is_closed', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_closed' => 'boolean',
        ];
    }
}
