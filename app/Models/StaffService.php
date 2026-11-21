<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffService extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = ['staff_id', 'service_id'];
}
