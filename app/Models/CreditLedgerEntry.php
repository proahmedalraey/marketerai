<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'generation_job_id', 'delta', 'balance_after',
        'reason', 'operation', 'note',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'decimal:1',
            'balance_after' => 'decimal:1',
        ];
    }

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'grant' => 'حصة اشتراك',
            'hold' => 'حجز',
            'settle' => 'تسوية',
            'refund' => 'إرجاع',
            'expire' => 'انتهاء صلاحية',
            'admin_adjust' => 'تعديل إداري',
            default => $this->reason,
        };
    }
}
