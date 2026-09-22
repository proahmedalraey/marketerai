<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Ready => 'جاهز',
            self::Scheduled => 'مجدول',
            self::Published => 'منشور',
            self::Archived => 'مؤرشف',
        };
    }

    /**
     * فئة الوسم الدلالية. لا ألوان خام هنا حتى يتبدّل الوضع الداكن من مكان واحد.
     */
    public function chipClasses(): string
    {
        return match ($this) {
            self::Draft => 'chip-neutral',
            self::Ready => 'chip-success',
            self::Scheduled => 'chip-info',
            self::Published => 'chip-brand',
            self::Archived => 'chip-quiet',
        };
    }

    /**
     * أيقونة مصاحبة للوسم: الحالة لا تُنقل باللون وحده (معيار WCAG 1.4.1).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'pencil',
            self::Ready => 'check-circle',
            self::Scheduled => 'clock',
            self::Published => 'globe',
            self::Archived => 'inbox',
        };
    }
}
