<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'conversation_id',
        'reply_to_message_id',
        'direction',
        'message_type',
        'body',
        'provider_message_id',
        'delivery_status',
        'metadata',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'metadata' => 'encrypted:array',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
