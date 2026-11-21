<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Tenant extends Model
{
    protected $fillable = ['name','slug','status','timezone','locale','settings'];
    protected function casts(): array { return ['settings' => 'array']; }
    public function users(): BelongsToMany {
        return $this->belongsToMany(User::class)->withPivot(['role','status'])->withTimestamps();
    }
}
