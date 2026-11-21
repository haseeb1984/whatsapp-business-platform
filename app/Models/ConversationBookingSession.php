<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationBookingSession extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'conversation_id',
        'state',
        'service_id',
        'staff_id',
        'local_date',
        'offered_services',
        'offered_staff',
        'offered_slots',
        'appointment_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'offered_services' => 'encrypted:array',
            'offered_staff' => 'encrypted:array',
            'offered_slots' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
