<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = ['whatsapp_wa_id', 'display_name', 'profile_metadata'];

    protected function casts(): array
    {
        return ['profile_metadata' => 'encrypted:array'];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
