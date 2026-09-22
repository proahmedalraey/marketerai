<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'locale', 'timezone', 'current_brand_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function currentBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'current_brand_id');
    }

    /**
     * البراند الفعّال: المختار صراحةً، وإلا أول براند للمستخدم.
     */
    public function resolveBrand(): ?Brand
    {
        return $this->currentBrand ?? $this->brands()->oldest('id')->first();
    }
}
