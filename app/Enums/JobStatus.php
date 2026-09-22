<?php

namespace App\Enums;

enum JobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'في الانتظار',
            self::Processing => 'قيد التنفيذ',
            self::Completed => 'اكتمل',
            self::Partial => 'اكتمل جزئياً',
            self::Failed => 'فشل',
            self::Cancelled => 'أُلغي',
        };
    }
}
