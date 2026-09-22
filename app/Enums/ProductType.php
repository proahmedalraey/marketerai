<?php

namespace App\Enums;

enum ProductType: string
{
    case Good = 'good';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'سلعة',
            self::Service => 'خدمة',
        };
    }

    /** اسم الأيقونة في مكوّن <x-icon>. */
    public function icon(): string
    {
        return match ($this) {
            self::Good => 'package',
            self::Service => 'briefcase',
        };
    }
}
