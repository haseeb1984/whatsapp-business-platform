<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentReschedule extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'appointment_id',
        'old_starts_at',
        'old_ends_at',
        'old_reserved_starts_at',
        'old_reserved_ends_at',
        'new_starts_at',
        'new_ends_at',
        'new_reserved_starts_at',
        'new_reserved_ends_at',
        'status',
        'idempotency_key',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'old_starts_at' => 'immutable_datetime',
            'old_ends_at' => 'immutable_datetime',
            'old_reserved_starts_at' => 'immutable_datetime',
            'old_reserved_ends_at' => 'immutable_datetime',
            'new_starts_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'new_reserved_starts_at' => 'immutable_datetime',
            'new_reserved_ends_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
