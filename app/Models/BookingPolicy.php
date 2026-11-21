<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BookingPolicy extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'minimum_notice_minutes',
        'booking_horizon_days',
        'slot_interval_minutes',
        'hold_minutes',
    ];

    protected function casts(): array
    {
        return [
            'minimum_notice_minutes' => 'integer',
            'booking_horizon_days' => 'integer',
            'slot_interval_minutes' => 'integer',
            'hold_minutes' => 'integer',
        ];
    }
}
