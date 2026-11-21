<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConnection extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'phone_number_id',
        'waba_id',
        'display_phone_number',
        'verified_name',
        'access_token',
        'status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
