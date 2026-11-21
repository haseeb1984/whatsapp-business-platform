<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'description',
        'price_amount',
        'currency',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }
}
