<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'customer_id',
        'service_id',
        'staff_id',
        'service_name',
        'price_amount',
        'currency',
        'duration_minutes',
        'starts_at',
        'ends_at',
        'reserved_starts_at',
        'reserved_ends_at',
        'status',
        'source',
        'idempotency_key',
        'calendar_sync_status',
        'external_event_id',
        'hold_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'duration_minutes' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reserved_starts_at' => 'immutable_datetime',
            'reserved_ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(AppointmentReschedule::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class);
    }
}
