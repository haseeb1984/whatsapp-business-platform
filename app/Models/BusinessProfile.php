<?php

namespace App\\Models;

use App\\Models\\Concerns\\BelongsToTenant;
use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class BusinessProfile extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'display_name',
        'description',
        'email',
        'phone',
        'website',
        'address',
        'timezone',
        'locale',
    ];
}
