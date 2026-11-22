<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'customer_id',
        'status',
        'automation_state',
        'assigned_user_id',
        'handover_requested_at',
        'handover_reason',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'immutable_datetime',
            'handover_requested_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function bookingSession(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ConversationBookingSession::class);
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Message::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
