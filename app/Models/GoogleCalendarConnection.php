<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GoogleCalendarConnection extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'connected_by_user_id',
        'google_account_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scope',
        'default_calendar_id',
        'status',
        'connected_at',
    ];

    public function connectedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
