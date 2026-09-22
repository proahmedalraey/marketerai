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
