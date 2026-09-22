<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = [
        'brand_id', 'generation_job_id', 'provider', 'model', 'operation',
        'tokens_in', 'tokens_out', 'images', 'latency_ms', 'cost_usd', 'succeeded',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'succeeded' => 'boolean',
        ];
    }
}
