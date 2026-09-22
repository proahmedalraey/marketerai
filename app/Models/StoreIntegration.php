<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

class StoreIntegration extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'provider', 'store_external_id', 'store_name',
        'access_token', 'refresh_token', 'expires_at',
        'status', 'last_synced_at', 'products_synced',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            'salla' => 'سلة',
            'zid' => 'زد',
            default => $this->provider,
        };
    }
}
