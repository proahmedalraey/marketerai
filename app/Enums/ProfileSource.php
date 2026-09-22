<?php

namespace App\Enums;

/**
 * من أين جاءت هذه النسخة. يظهر في قائمة الإصدارات،
 * ويحكم أيضاً هل يُنشئ التحرير اليدوي نسخة جديدة أم يعدّل في مكانه.
 */
enum ProfileSource: string
{
    case Generated = 'generated';
    case ManualEdit = 'manual_edit';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'توليد بالذكاء',
            self::ManualEdit => 'تحرير يدوي',
            self::Restored => 'استعادة نسخة',
        };
    }
}
