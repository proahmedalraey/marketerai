<?php

namespace App\Enums;

enum PostStatus: string
{
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'مجدول',
            self::Publishing => 'جارٍ النشر',
            self::Published => 'تم النشر',
            self::Failed => 'فشل النشر',
            self::Cancelled => 'ملغي',
        };
    }

    public function chipClasses(): string
    {
        return match ($this) {
            self::Queued => 'chip-info',
            self::Publishing => 'chip-brand',
            self::Published => 'chip-success',
            self::Failed => 'chip-danger',
            self::Cancelled => 'chip-quiet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'clock',
            self::Publishing => 'refresh',
            self::Published => 'globe',
            self::Failed => 'alert-circle',
            self::Cancelled => 'close',
        };
    }
}
