<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentReminder extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'appointment_id',
        'channel',
        'reminder_type',
        'scheduled_at',
        'status',
        'idempotency_key',
        'queued_at',
        'attempts',
        'sent_at',
        'provider_message_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
