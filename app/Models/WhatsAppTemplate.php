<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'whatsapp_connection_id',
        'provider_template_id',
        'name',
        'language',
        'category',
        'status',
        'components',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
