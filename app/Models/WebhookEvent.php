<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_event_key',
        'phone_number_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'received_at',
        'processed_at',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
