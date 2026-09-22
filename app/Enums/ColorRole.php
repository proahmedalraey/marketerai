<?php

namespace App\Enums;

/**
 * دور اللون لا اسمه: النموذج يحتاج أن يعرف أين يضع كل لون،
 * و«#111827» وحده لا يقول إن كان خلفية أو نصاً.
 */
enum ColorRole: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Accent = 'accent';
    case Background = 'background';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'رئيسي',
            self::Secondary => 'ثانوي',
            self::Accent => 'مميّز',
            self::Background => 'خلفية',
            self::Text => 'نص',
        };
    }

    /** توجيه يُقرأ في برومبت الصور. */
    public function directive(): string
    {
        return match ($this) {
            self::Primary => 'اللون الحاكم في التصميم',
            self::Secondary => 'لون مساند للتفاصيل والحدود',
            self::Accent => 'لون التمييز: أزرار ونقاط الجذب فقط',
            self::Background => 'لون الخلفية',
            self::Text => 'لون النص',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role) => [$role->value => $role->label()])
            ->all();
    }
}
