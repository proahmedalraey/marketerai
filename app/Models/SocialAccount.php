<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'platform', 'external_id', 'username', 'display_name', 'avatar_url',
        'access_token', 'refresh_token', 'expires_at', 'scopes', 'status', 'last_checked_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            // التوكنات مشفرة في قاعدة البيانات
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function needsRefresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->addHours(48));
    }

    public function platformLabel(): string
    {
        return config("content.platforms.{$this->platform}.label", $this->platform);
    }
}
